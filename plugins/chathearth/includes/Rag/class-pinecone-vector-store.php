<?php
/**
 * Pinecone HTTP vector store.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Logger;
use ChatHearth\Options;

/**
 * Upsert/query/delete against a Pinecone index host.
 */
final class Pinecone_Vector_Store implements Vector_Store_Interface {

	/**
	 * Index host (https://....pinecone.io).
	 *
	 * @var string
	 */
	private $host;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Optional namespace.
	 *
	 * @var string
	 */
	private $namespace;

	/**
	 * Constructor.
	 *
	 * @param string $host    Index host.
	 * @param string $api_key API key.
	 * @param string $ns      Namespace.
	 */
	public function __construct( string $host = '', string $api_key = '', string $ns = '' ) {
		$this->host      = untrailingslashit( '' !== $host ? $host : (string) Options::get( 'rag_pinecone_host', '' ) );
		$this->api_key   = '' !== $api_key ? $api_key : (string) Options::get( 'rag_pinecone_api_key', '' );
		$this->namespace = '' !== $ns ? $ns : (string) Options::get( 'rag_pinecone_namespace', '' );
	}

	/**
	 * Insert or replace records.
	 *
	 * @param array $records Records.
	 */
	public function upsert( array $records ): bool {
		if ( empty( $records ) ) {
			return true;
		}

		$vectors = array();
		foreach ( $records as $record ) {
			$meta            = isset( $record['meta'] ) && is_array( $record['meta'] ) ? $record['meta'] : array();
			$meta['content'] = mb_substr( (string) $record['content'], 0, 8000, 'UTF-8' );
			$vectors[]       = array(
				'id'       => (string) $record['id'],
				'values'   => $record['embedding'],
				'metadata' => $this->scalar_meta( $meta ),
			);
		}

		$body = array( 'vectors' => $vectors );
		if ( '' !== $this->namespace ) {
			$body['namespace'] = $this->namespace;
		}

		$response = $this->request( '/vectors/upsert', $body );
		return is_array( $response );
	}

	/**
	 * Delete records by id.
	 *
	 * @param array $ids Vector ids.
	 */
	public function delete( array $ids ): bool {
		if ( empty( $ids ) ) {
			return true;
		}

		$body = array( 'ids' => array_values( $ids ) );
		if ( '' !== $this->namespace ) {
			$body['namespace'] = $this->namespace;
		}

		$response = $this->request( '/vectors/delete', $body );
		return is_array( $response );
	}

	/**
	 * Nearest neighbours.
	 *
	 * @param array $embedding Query vector.
	 * @param int   $limit     Max hits.
	 * @return array
	 */
	public function query( array $embedding, int $limit = 5 ): array {
		$limit = max( 1, min( 20, $limit ) );
		$body  = array(
			'vector'          => $embedding,
			'topK'            => $limit,
			'includeMetadata' => true,
			'includeValues'   => false,
		);
		if ( '' !== $this->namespace ) {
			$body['namespace'] = $this->namespace;
		}

		$response = $this->request( '/query', $body );
		if ( ! is_array( $response ) || empty( $response['matches'] ) || ! is_array( $response['matches'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $response['matches'] as $match ) {
			if ( ! is_array( $match ) ) {
				continue;
			}
			$meta  = isset( $match['metadata'] ) && is_array( $match['metadata'] ) ? $match['metadata'] : array();
			$out[] = array(
				'id'      => isset( $match['id'] ) ? (string) $match['id'] : '',
				'score'   => isset( $match['score'] ) ? (float) $match['score'] : 0.0,
				'content' => isset( $meta['content'] ) ? (string) $meta['content'] : '',
				'meta'    => $meta,
			);
		}

		return $out;
	}

	/**
	 * {@inheritdoc}
	 */
	public function ping(): bool {
		if ( '' === $this->host || '' === $this->api_key ) {
			return false;
		}

		$response = wp_remote_get(
			$this->host . '/describe_index_stats',
			array(
				'timeout' => 8,
				'headers' => $this->headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/**
	 * POST JSON to the index host.
	 *
	 * @param string               $path Path.
	 * @param array<string, mixed> $body Body.
	 * @return array<string, mixed>|null
	 */
	private function request( string $path, array $body ) {
		if ( '' === $this->host || '' === $this->api_key ) {
			Logger::error( 'Pinecone is not configured.' );
			return null;
		}

		$response = wp_remote_post(
			$this->host . $path,
			array(
				'timeout' => 20,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'Pinecone request failed.', array( 'error' => $response->get_error_message() ) );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			Logger::error( 'Pinecone unexpected response.', array( 'status' => $code ) );
			return null;
		}

		return $data;
	}

	/**
	 * Auth headers.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array(
			'Api-Key'      => $this->api_key,
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
	}

	/**
	 * Flatten metadata.
	 *
	 * @param array<string, mixed> $meta Meta.
	 * @return array<string, scalar>
	 */
	private function scalar_meta( array $meta ): array {
		$out = array();
		foreach ( $meta as $key => $value ) {
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_string( $value ) ) {
				$out[ (string) $key ] = $value;
			}
		}

		return $out;
	}
}
