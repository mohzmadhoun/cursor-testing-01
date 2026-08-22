<?php
/**
 * REST chat endpoint.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Ai\Ai_Gateway;
use ChatHearth\Commerce\Cart_Service;
use ChatHearth\Options;
use ChatHearth\Rag\Current_Page;
use ChatHearth\Rag\Retriever;
use ChatHearth\Security\Content_Moderation;
use ChatHearth\Security\Rate_Limiter;
use ChatHearth\Security\Recaptcha;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Chat REST controller.
 */
final class Chat_Controller {

	public const REST_NAMESPACE = 'chathearth/v1';

	/** Must be `wp_rest` — WordPress REST cookie auth validates X-WP-Nonce against this action. */
	public const NONCE_ACTION = 'wp_rest';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_chat' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'message'         => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'history'         => array(
						'required'          => false,
						'type'              => 'array',
						'default'           => array(),
						'sanitize_callback' => array( $this, 'sanitize_history' ),
					),
					'recaptcha_token' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page_id'         => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'page_type'       => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'page_taxonomy'   => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'page_url'        => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/recaptcha',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_recaptcha' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'recaptcha_token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Sanitize chat history turns from the REST request.
	 *
	 * @param mixed           $history History param.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Param name.
	 * @return list<array{role:string,content:string}>
	 */
	public function sanitize_history( $history, $request, string $param ): array {
		unset( $request, $param );

		if ( ! is_array( $history ) ) {
			return array();
		}

		$out = array();
		foreach ( $history as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}

			$role    = isset( $turn['role'] ) ? sanitize_key( (string) $turn['role'] ) : '';
			$content = isset( $turn['content'] ) ? sanitize_textarea_field( (string) $turn['content'] ) : '';

			if ( ! in_array( $role, array( 'user', 'assistant' ), true ) || '' === $content ) {
				continue;
			}

			$out[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		return $out;
	}

	/**
	 * Allow guests and logged-in users when a valid REST nonce is present.
	 *
	 * WordPress already rejects invalid X-WP-Nonce values via rest_cookie_check_errors
	 * before this runs (when the header is sent). We still verify here for clarity.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permission_check( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! $nonce || ! wp_verify_nonce( (string) $nonce, self::NONCE_ACTION ) ) {
			return new WP_Error(
				'chathearth_invalid_nonce',
				__( 'Invalid security token. Please refresh the page and try again.', 'chathearth' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Unlock the chat overlay after a successful reCAPTCHA v3 check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_recaptcha( WP_REST_Request $request ) {
		if ( ! Options::is_chat_enabled() ) {
			return new WP_Error(
				'chathearth_disabled',
				__( 'The chatbot is currently disabled.', 'chathearth' ),
				array( 'status' => 403 )
			);
		}

		$captcha = ( new Recaptcha() )->verify_or_error( (string) $request->get_param( 'recaptcha_token' ) );
		if ( is_wp_error( $captcha ) ) {
			return $captcha;
		}

		return new WP_REST_Response(
			array(
				'ok' => true,
			),
			200
		);
	}

	/**
	 * Handle chat request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_chat( WP_REST_Request $request ) {
		if ( ! Options::is_chat_enabled() ) {
			return new WP_Error(
				'chathearth_disabled',
				__( 'The chatbot is currently disabled.', 'chathearth' ),
				array( 'status' => 403 )
			);
		}

		$captcha = ( new Recaptcha() )->verify_or_error( (string) $request->get_param( 'recaptcha_token' ) );
		if ( is_wp_error( $captcha ) ) {
			return $captcha;
		}

		$rate = ( new Rate_Limiter() )->allow_or_error();
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$message = trim( (string) $request->get_param( 'message' ) );
		$max_len = (int) Options::get( 'max_message_length', 2000 );

		if ( '' === $message ) {
			return new WP_Error(
				'chathearth_empty_message',
				__( 'Please enter a message.', 'chathearth' ),
				array( 'status' => 400 )
			);
		}

		if ( mb_strlen( $message, 'UTF-8' ) > $max_len ) {
			$message = mb_substr( $message, 0, $max_len, 'UTF-8' );
		}

		$history_raw = $request->get_param( 'history' );
		$history     = is_array( $history_raw ) ? $history_raw : array();
		$max_history = (int) Options::get( 'max_history_messages', 20 );

		foreach ( $history as $i => $turn ) {
			$content = isset( $turn['content'] ) ? (string) $turn['content'] : '';
			if ( mb_strlen( $content, 'UTF-8' ) > $max_len ) {
				$history[ $i ]['content'] = mb_substr( $content, 0, $max_len, 'UTF-8' );
			}
		}

		if ( count( $history ) > $max_history ) {
			$history = array_slice( $history, -$max_history );
		}

		$moderation = ( new Content_Moderation() )->check_or_error( $message, $history );
		if ( is_wp_error( $moderation ) ) {
			return $moderation;
		}

		Current_Page::instance()->capture(
			(int) $request->get_param( 'page_id' ),
			(string) $request->get_param( 'page_type' ),
			(string) $request->get_param( 'page_taxonomy' ),
			(string) $request->get_param( 'page_url' )
		);

		$reply = ( new Ai_Gateway() )->generate_reply( $message, $history );

		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$retriever = Retriever::instance();
		$current   = Current_Page::instance();

		return new WP_REST_Response(
			array(
				'reply'    => $reply,
				'sources'  => self::merge_unique_sources( $current->source(), $retriever->last_sources() ),
				'products' => self::merge_unique_products( $current->product(), $retriever->last_products() ),
				'commerce' => array(
					'enabled'      => Cart_Service::is_available(),
					'cart_url'     => Cart_Service::is_available() ? Cart_Service::cart_url() : '',
					'checkout_url' => Cart_Service::is_available() ? Cart_Service::checkout_url() : '',
				),
			),
			200
		);
	}

	/**
	 * Prepend the current-page citation when it is not already in the RAG list.
	 *
	 * @param array<string, mixed>|null  $current Current page source.
	 * @param list<array<string, mixed>> $sources RAG sources.
	 * @return list<array<string, mixed>>
	 */
	private static function merge_unique_sources( $current, array $sources ): array {
		if ( ! is_array( $current ) ) {
			return $sources;
		}

		$url = isset( $current['url'] ) ? (string) $current['url'] : '';
		if ( '' === $url ) {
			return $sources;
		}

		foreach ( $sources as $source ) {
			if ( isset( $source['url'] ) && (string) $source['url'] === $url ) {
				return $sources;
			}
		}

		array_unshift( $sources, $current );

		return $sources;
	}

	/**
	 * Prepend the current-page product when it is not already listed.
	 *
	 * @param array<string, mixed>|null  $current Current product.
	 * @param list<array<string, mixed>> $products RAG/catalog products.
	 * @return list<array<string, mixed>>
	 */
	private static function merge_unique_products( $current, array $products ): array {
		if ( ! is_array( $current ) ) {
			return $products;
		}

		$id = isset( $current['id'] ) ? (int) $current['id'] : 0;
		if ( $id <= 0 ) {
			return $products;
		}

		foreach ( $products as $product ) {
			if ( isset( $product['id'] ) && (int) $product['id'] === $id ) {
				return $products;
			}
		}

		array_unshift( $products, $current );

		return $products;
	}
}
