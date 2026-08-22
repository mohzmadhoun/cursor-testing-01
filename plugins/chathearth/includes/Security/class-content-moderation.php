<?php
/**
 * Input content moderation (keywords + OpenAI Moderations API).
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Ai\Openai_Credentials;
use ChatHearth\Logger;
use ChatHearth\Options;
use ChatHearth\Plugin;
use WP_Error;

/**
 * Blocks harmful / disallowed user content before it reaches the chat model.
 */
final class Content_Moderation {

	private const MODERATION_MODEL = 'omni-moderation-latest';

	/** Max characters sent to the Moderations API. */
	private const MAX_MODERATION_INPUT = 10000;

	/**
	 * Check message (and user history turns) against keyword list and OpenAI Moderations.
	 *
	 * @param string                                          $message Current user message.
	 * @param list<array{role?:string,content?:string}>|array $history Prior turns (client-controlled).
	 * @return true|WP_Error
	 */
	public function check_or_error( string $message, array $history = array() ) {
		if ( ! Options::is_content_moderation_enabled() ) {
			return true;
		}

		$text = $this->build_moderation_text( $message, $history );

		$keyword = $this->check_keywords( $text );
		if ( is_wp_error( $keyword ) ) {
			return $keyword;
		}

		if ( Options::is_moderation_openai_enabled() && Plugin::is_openai_ready() ) {
			$openai = $this->check_openai_moderation( $text );
			if ( is_wp_error( $openai ) ) {
				return $openai;
			}
		}

		/**
		 * Filter whether content is allowed after built-in moderation layers.
		 *
		 * Return false to block; true to allow. Third argument is the layer that
		 * last evaluated ("keywords" or "openai" or "complete").
		 *
		 * @param bool   $allowed Whether the content may proceed.
		 * @param string $text    Combined text that was checked.
		 * @param string $source  Check source label.
		 */
		$allowed = (bool) apply_filters( 'chathearth_content_allowed', true, $text, 'complete' );
		if ( ! $allowed ) {
			return $this->blocked_error();
		}

		return true;
	}

	/**
	 * Build the string checked by both layers (current message + user history).
	 *
	 * @param string                                          $message Current message.
	 * @param list<array{role?:string,content?:string}>|array $history History turns.
	 */
	private function build_moderation_text( string $message, array $history ): string {
		$parts = array( $message );

		foreach ( $history as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}
			$role = isset( $turn['role'] ) ? (string) $turn['role'] : '';
			if ( 'user' !== $role ) {
				continue;
			}
			$content = isset( $turn['content'] ) ? trim( (string) $turn['content'] ) : '';
			if ( '' !== $content ) {
				$parts[] = $content;
			}
		}

		$text = implode( "\n", $parts );
		if ( mb_strlen( $text, 'UTF-8' ) > self::MAX_MODERATION_INPUT ) {
			$text = mb_substr( $text, 0, self::MAX_MODERATION_INPUT, 'UTF-8' );
		}

		return $text;
	}

	/**
	 * Case-insensitive substring match against the admin + filtered phrase list.
	 *
	 * @param string $text Combined text.
	 * @return true|WP_Error
	 */
	private function check_keywords( string $text ) {
		$phrases = Options::moderation_disallowed_phrases_list();

		/**
		 * Filter the disallowed phrases used for keyword moderation.
		 *
		 * @param list<string> $phrases Phrases (already trimmed, non-empty).
		 */
		$phrases = apply_filters( 'chathearth_disallowed_phrases', $phrases );
		if ( empty( $phrases ) ) {
			return true;
		}

		foreach ( $phrases as $phrase ) {
			$phrase = trim( (string) $phrase );
			if ( '' === $phrase ) {
				continue;
			}
			if ( false !== mb_stripos( $text, $phrase, 0, 'UTF-8' ) ) {
				/**
				 * Filter whether content is allowed after a keyword hit.
				 *
				 * @param bool   $allowed Whether the content may proceed.
				 * @param string $text    Combined text that was checked.
				 * @param string $source  Check source label.
				 */
				$allowed = (bool) apply_filters( 'chathearth_content_allowed', false, $text, 'keywords' );
				if ( ! $allowed ) {
					return $this->blocked_error();
				}
			}
		}

		return true;
	}

	/**
	 * Call OpenAI Moderations; fail open on transport / parse errors.
	 *
	 * @param string $text Combined text.
	 * @return true|WP_Error
	 */
	private function check_openai_moderation( string $text ) {
		$api_key  = $this->resolve_openai_api_key();
		$endpoint = Openai_Credentials::endpoint( 'moderations' );
		if ( '' === $api_key || '' === $endpoint ) {
			Logger::error( 'OpenAI moderation skipped: no API key available via AI Client registry.' );
			return true;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model' => self::MODERATION_MODEL,
						'input' => $text,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error(
				'OpenAI moderation request failed.',
				array( 'error' => $response->get_error_message() )
			);
			return true;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code || ! is_array( $data ) ) {
			Logger::error(
				'OpenAI moderation unexpected response.',
				array(
					'status' => $code,
				)
			);
			return true;
		}

		$flagged = false;
		if ( isset( $data['results'][0]['flagged'] ) ) {
			$flagged = (bool) $data['results'][0]['flagged'];
		}

		if ( ! $flagged ) {
			return true;
		}

		/**
		 * Filter whether content is allowed after OpenAI Moderations flags it.
		 *
		 * @param bool   $allowed Whether the content may proceed.
		 * @param string $text    Combined text that was checked.
		 * @param string $source  Check source label.
		 */
		$allowed = (bool) apply_filters( 'chathearth_content_allowed', false, $text, 'openai' );
		if ( ! $allowed ) {
			return $this->blocked_error();
		}

		return true;
	}

	/**
	 * Resolve OpenAI API key from the AI Client registry (never reads Connectors options directly).
	 */
	private function resolve_openai_api_key(): string {
		return Openai_Credentials::api_key();
	}

	/**
	 * Shared blocked response for keyword and OpenAI hits.
	 */
	private function blocked_error(): WP_Error {
		$message = trim( (string) Options::get( 'moderation_block_message', '' ) );
		if ( '' === $message ) {
			$message = __(
				'Sorry, that message cannot be processed. Please rephrase and try again.',
				'chathearth'
			);
		}

		return new WP_Error(
			'chathearth_content_blocked',
			$message,
			array( 'status' => 422 )
		);
	}
}
