<?php
/**
 * Chroma HTTP vector store (self-hosted).
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
 * Talks to a Chroma server over HTTP (v2 with v1 fallback).
 */
final class Chroma_Vector_Store implements Vector_Store_Interface {

	/**
	 * Base URL without trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Collection name.
	 *
	 * @var string
	 */
	private $collection;

	/**
	 * Tenant (v2).
	 *
	 * @var string
	 */
	private $tenant;

	/**
	 * Database (v2).
	 *
	 * @var string
	 */
	private $database;

	/**
	 * Detected API version: v1 or v2.
	 *
	 * @var string
	 */
	private $api = '';

	/**
	 * Constructor.
	 *
	 * @param string $base_url    Server URL.
	 * @param string $collection  Collection name.
	 * @param string $tenant      Tenant.
	 * @param string $database    Database.
	 */
	public function __construct( string $base_url = '', string $collection = '', string $tenant = '', string $database = '' ) {
		$this->base_url   = untrailingslashit( '' !== $base_url ? $base_url : (string) Options::get( 'rag_chroma_url', 'http://127.0.0.1:8000' ) );
		$this->collection = '' !== $collection ? $collection : (string) Options::get( 'rag_chroma_collection', 'chathearth' );
		$this->tenant     = '' !== $tenant ? $tenant : (string) Options::get( 'rag_chroma_tenant', 'default_tenant' );
		$this->database   = '' !== $database ? $database : (string) Options::get( 'rag_chroma_database', 'default_database' );
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
		if ( ! $this->ensure_collection() ) {
			return false;
		}

		$ids        = array();
		$embeddings = array();
		$documents  = array();
		$metadatas  = array();
		foreach ( $records as $record ) {
			$ids[]        = (string) $record['id'];
			$embeddings[] = $record['embedding'];
			$documents[]  = (string) $record['content'];
			$metadatas[]  = $this->scalar_meta( isset( $record['meta'] ) && is_array( $record['meta'] ) ? $record['meta'] : array() );
		}

		$body = array(
			'ids'        => $ids,
			'embeddings' => $embeddings,
			'documents'  => $documents,
			'metadatas'  => $metadatas,
		);

		$response = $this->request( 'POST', $this->collection_path( 'upsert' ), $body );
		return $this->is_ok( $response );
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
		if ( ! $this->ensure_collection() ) {
			return false;
		}

		$response = $this->request(
			'POST',
			$this->collection_path( 'delete' ),
			array( 'ids' => array_values( $ids ) )
		);

		return $this->is_ok( $response );
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
		if ( ! $this->ensure_collection() ) {
			return array();
		}

		$response = $this->request(
			'POST',
			$this->collection_path( 'query' ),
			array(
				'query_embeddings' => array( $embedding ),
				'n_results'        => $limit,
				'include'          => array( 'documents', 'metadatas', 'distances' ),
			)
		);

		if ( ! is_array( $response ) ) {
			return array();
		}

		$ids       = isset( $response['ids'][0] ) && is_array( $response['ids'][0] ) ? $response['ids'][0] : array();
		$docs      = isset( $response['documents'][0] ) && is_array( $response['documents'][0] ) ? $response['documents'][0] : array();
		$metas     = isset( $response['metadatas'][0] ) && is_array( $response['metadatas'][0] ) ? $response['metadatas'][0] : array();
		$distances = isset( $response['distances'][0] ) && is_array( $response['distances'][0] ) ? $response['distances'][0] : array();

		$out = array();
		foreach ( $ids as $i => $id ) {
			$distance = isset( $distances[ $i ] ) ? (float) $distances[ $i ] : 1.0;
			$meta     = isset( $metas[ $i ] ) && is_array( $metas[ $i ] ) ? $metas[ $i ] : array();
			$out[]    = array(
				'id'      => (string) $id,
				'score'   => 1.0 / ( 1.0 + max( 0.0, $distance ) ),
				'content' => isset( $docs[ $i ] ) ? (string) $docs[ $i ] : '',
				'meta'    => $meta,
			);
		}

		return $out;
	}

	/**
	 * {@inheritdoc}
	 */
	public function ping(): bool {
		$status = $this->ping_status();
		return ! empty( $status['ok'] );
	}

	/**
	 * Heartbeat with a human-readable error.
	 *
	 * @return array{ok:bool,api:string,url:string,message:string}
	 */
	public function ping_status(): array {
		$url = $this->base_url;
		if ( '' === $url ) {
			return array(
				'ok'      => false,
				'api'     => '',
				'url'     => '',
				'message' => __( 'No Chroma URL is set. Save a server URL such as http://127.0.0.1:8000, or use Local (WordPress database).', 'chathearth' ),
			);
		}

		$attempts = array(
			array(
				'path' => '/api/v2/heartbeat',
				'api'  => 'v2',
			),
			array(
				'path' => '/api/v1/heartbeat',
				'api'  => 'v1',
			),
			array(
				'path' => '/heartbeat',
				'api'  => 'v2',
			),
		);

		$last = '';
		foreach ( $attempts as $attempt ) {
			$response = wp_remote_get(
				$url . $attempt['path'],
				array(
					'timeout'   => 4,
					'sslverify' => true,
				)
			);
			if ( is_wp_error( $response ) ) {
				$last = $response->get_error_message();
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 === $code ) {
				$this->api = $attempt['api'];
				return array(
					'ok'      => true,
					'api'     => $this->api,
					'url'     => $url,
					'message' => sprintf(
						/* translators: 1: Chroma API version, 2: server URL */
						__( 'Connected to Chroma (%1$s) at %2$s.', 'chathearth' ),
						$this->api,
						$url
					),
				);
			}
			$last = sprintf(
				/* translators: 1: HTTP status code, 2: request URL */
				__( 'HTTP %1$d from %2$s.', 'chathearth' ),
				$code,
				$url . $attempt['path']
			);
		}

		$persist = Schema::chroma_dir();
		$hint    = sprintf(
			/* translators: 1: Chroma base URL, 2: persist directory, 3: example CLI */
			__( 'WordPress cannot open a Chroma data folder by itself; it talks to Chroma over HTTP. Nothing answered at %1$s. On this server, start Chroma with a persist directory (files are created by that process, not by PHP): %3$s — then Test again. Persist path: %2$s. If you do not want to run Chroma, switch the vector store to Local (WordPress database).', 'chathearth' ),
			$url,
			$persist,
			'chroma run --path "' . $persist . '" --host 127.0.0.1 --port 8000'
		);

		return array(
			'ok'      => false,
			'api'     => '',
			'url'     => $url,
			'message' => ( '' !== $last ? $last . ' ' : '' ) . $hint,
		);
	}

	/**
	 * Detect API version from heartbeat.
	 */
	private function detect_api(): string {
		if ( '' !== $this->api ) {
			return $this->api;
		}

		$status = $this->ping_status();
		return (string) $status['api'];
	}

	/**
	 * Create the collection if needed.
	 */
	private function ensure_collection(): bool {
		$api = $this->detect_api();
		if ( '' === $api ) {
			Logger::error( 'Chroma heartbeat failed.' );
			return false;
		}

		if ( 'v2' === $api ) {
			$this->request( 'POST', '/api/v2/tenants', array( 'name' => $this->tenant ) );
			$this->request(
				'POST',
				'/api/v2/tenants/' . rawurlencode( $this->tenant ) . '/databases',
				array( 'name' => $this->database )
			);
			$created = $this->request(
				'POST',
				$this->v2_collections_root(),
				array( 'name' => $this->collection )
			);
			if ( is_array( $created ) || $this->collection_exists() ) {
				return true;
			}
			return $this->collection_exists();
		}

		$this->request( 'POST', '/api/v1/collections', array( 'name' => $this->collection ) );
		return true;
	}

	/**
	 * Whether the named collection exists.
	 */
	private function collection_exists(): bool {
		$get = $this->request( 'GET', $this->collection_get_path(), null );
		return is_array( $get );
	}

	/**
	 * Path for collection sub-resource.
	 *
	 * @param string $action upsert|delete|query.
	 */
	private function collection_path( string $action ): string {
		if ( 'v2' === $this->detect_api() ) {
			return $this->v2_collections_root() . '/' . rawurlencode( $this->collection ) . '/' . $action;
		}

		return '/api/v1/collections/' . rawurlencode( $this->collection ) . '/' . $action;
	}

	/**
	 * GET collection path.
	 */
	private function collection_get_path(): string {
		if ( 'v2' === $this->detect_api() ) {
			return $this->v2_collections_root() . '/' . rawurlencode( $this->collection );
		}

		return '/api/v1/collections/' . rawurlencode( $this->collection );
	}

	/**
	 * V2 collections root.
	 */
	private function v2_collections_root(): string {
		return '/api/v2/tenants/' . rawurlencode( $this->tenant ) . '/databases/' . rawurlencode( $this->database ) . '/collections';
	}

	/**
	 * HTTP helper.
	 *
	 * @param string                    $method GET or POST.
	 * @param string                    $path   Path.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @return array<string, mixed>|null
	 */
	private function request( string $method, string $path, $body ) {
		$args = array(
			'timeout' => 20,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
		);
		$url  = $this->base_url . $path;

		if ( 'GET' === $method ) {
			$response = wp_remote_get( $url, $args );
		} else {
			$args['body'] = null === $body ? '{}' : wp_json_encode( $body );
			$response     = wp_remote_post( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			Logger::error( 'Chroma request failed.', array( 'error' => $response->get_error_message() ) );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			return is_array( $data ) ? $data : null;
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Whether a mutation response looks successful.
	 *
	 * @param array<string, mixed>|null $response Response.
	 */
	private function is_ok( $response ): bool {
		return is_array( $response );
	}

	/**
	 * Flatten metadata to scalars for Chroma.
	 *
	 * @param array<string, mixed> $meta Meta.
	 * @return array<string, scalar>
	 */
	private function scalar_meta( array $meta ): array {
		$out = array();
		foreach ( $meta as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$out[ (string) $key ] = $value;
			}
		}

		return $out;
	}
}
