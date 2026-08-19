<?php
/**
 * Cart REST route for the chat widget.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Commerce\Cart_Service;
use ChatHearth\Commerce\Product_Catalog;
use ChatHearth\Options;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Guest-accessible add-to-cart using the same REST nonce as chat.
 */
final class Cart_Controller {

	public const REST_NAMESPACE = 'chathearth/v1';

	public const NONCE_ACTION = 'wp_rest';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/cart',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_add' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'product_id'   => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'quantity'     => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'variation_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Same nonce gate as chat.
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
	 * Add to cart.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_add( WP_REST_Request $request ) {
		if ( ! Options::is_chat_enabled() ) {
			return new WP_Error(
				'chathearth_disabled',
				__( 'The chatbot is currently disabled.', 'chathearth' ),
				array( 'status' => 403 )
			);
		}

		if ( ! Cart_Service::is_available() ) {
			return new WP_Error(
				'chathearth_cart_unavailable',
				__( 'The store cart is not available.', 'chathearth' ),
				array( 'status' => 400 )
			);
		}

		$product_id   = (int) $request->get_param( 'product_id' );
		$quantity     = (int) $request->get_param( 'quantity' );
		$variation_id = (int) $request->get_param( 'variation_id' );

		$result = ( new Cart_Service() )->add( $product_id, $quantity, $variation_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$product           = ( new Product_Catalog() )->get_public_product( $product_id );
		$result['product'] = is_array( $product ) ? $product : null;

		return new WP_REST_Response( $result, 200 );
	}
}
