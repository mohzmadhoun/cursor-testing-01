<?php
/**
 * OpenAI embeddings via the Connectors-stored API key.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Ai\Openai_Credentials;
use ChatHearth\Logger;
use WP_Error;

/**
 * Embeds text with text-embedding-3-small.
 */
final class Embedding_Client {

	private const ENDPOINT = 'https://api.openai.com/v1/embeddings';

	private const MODEL = 'text-embedding-3-small';

	private const MAX_CHARS = 8000;

	/**
	 * Embed one string.
	 *
	 * @param string $text Input.
	 * @return list<float>|WP_Error
	 */
	public function embed( string $text ) {
		$batch = $this->embed_many( array( $text ) );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}

		return isset( $batch[0] ) ? $batch[0] : new WP_Error( 'chathearth_embed_empty', 'Empty embedding response.' );
	}

	/**
	 * Embed many strings (order preserved).
	 *
	 * @param array $texts Inputs.
	 * @return list<list<float>>|WP_Error
	 */
	public function embed_many( array $texts ) {
		$prepared = array();
		foreach ( $texts as $text ) {
			$text = trim( (string) $text );
			if ( mb_strlen( $text, 'UTF-8' ) > self::MAX_CHARS ) {
				$text = mb_substr( $text, 0, self::MAX_CHARS, 'UTF-8' );
			}
			$prepared[] = '' !== $text ? $text : ' ';
		}

		if ( empty( $prepared ) ) {
			return array();
		}

		$filtered = apply_filters( 'chathearth_pre_embed_batch', null, $prepared );
		if ( is_array( $filtered ) ) {
			return $filtered;
		}

		$single_filter = array();
		$used_filter   = false;
		foreach ( $prepared as $item ) {
			$pre = apply_filters( 'chathearth_pre_embed', null, $item );
			if ( is_array( $pre ) ) {
				$used_filter     = true;
				$single_filter[] = $pre;
			} else {
				$single_filter[] = null;
			}
		}
		if ( $used_filter && ! in_array( null, $single_filter, true ) ) {
			return $single_filter;
		}

		$api_key = Openai_Credentials::api_key();
		if ( '' === $api_key ) {
			return new WP_Error(
				'chathearth_embed_no_key',
				__( 'OpenAI is not configured, so embeddings cannot be created.', 'chathearth' )
			);
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model' => self::MODEL,
						'input' => $prepared,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'Embedding request failed.', array( 'error' => $response->get_error_message() ) );
			return new WP_Error( 'chathearth_embed_http', __( 'Embedding request failed.', 'chathearth' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) || empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			Logger::error( 'Embedding unexpected response.', array( 'status' => $code ) );
			return new WP_Error( 'chathearth_embed_http', __( 'Embedding request failed.', 'chathearth' ) );
		}

		usort(
			$body['data'],
			static function ( $a, $b ): int {
				$ia = is_array( $a ) && isset( $a['index'] ) ? (int) $a['index'] : 0;
				$ib = is_array( $b ) && isset( $b['index'] ) ? (int) $b['index'] : 0;
				return $ia <=> $ib;
			}
		);

		$out = array();
		foreach ( $body['data'] as $row ) {
			if ( ! is_array( $row ) || empty( $row['embedding'] ) || ! is_array( $row['embedding'] ) ) {
				return new WP_Error( 'chathearth_embed_http', __( 'Embedding request failed.', 'chathearth' ) );
			}
			$out[] = array_map( 'floatval', $row['embedding'] );
		}

		return $out;
	}
}
