<?php
/**
 * Build the configured vector store.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Options;

/**
 * Factory for Vector_Store_Interface implementations.
 */
final class Vector_Store_Factory {

	/**
	 * Create the store selected in settings.
	 */
	public static function make(): Vector_Store_Interface {
		$driver = (string) Options::get( 'rag_vector_store', 'builtin' );
		switch ( $driver ) {
			case 'chroma':
				$store = new Chroma_Vector_Store();
				break;
			case 'pinecone':
				$store = new Pinecone_Vector_Store();
				break;
			case 'builtin':
			default:
				$store = new Builtin_Vector_Store();
				break;
		}

		$filtered = apply_filters( 'chathearth_vector_store', $store, $driver );
		if ( $filtered instanceof Vector_Store_Interface ) {
			return $filtered;
		}

		return $store;
	}

	/**
	 * Labels for the settings dropdown.
	 *
	 * @return array<string, string>
	 */
	public static function drivers(): array {
		return array(
			'builtin'  => __( 'Local (WordPress database)', 'chathearth' ),
			'chroma'   => __( 'Chroma (self-hosted HTTP)', 'chathearth' ),
			'pinecone' => __( 'Pinecone', 'chathearth' ),
		);
	}

	/**
	 * Ping the selected driver (optionally overriding the saved Chroma URL).
	 *
	 * @param string $driver     Store id.
	 * @param string $chroma_url Optional Chroma base URL.
	 * @return array<string, mixed>
	 */
	public static function ping_status( string $driver = '', string $chroma_url = '' ): array {
		if ( '' === $driver ) {
			$driver = (string) Options::get( 'rag_vector_store', 'builtin' );
		}

		switch ( $driver ) {
			case 'chroma':
				$status          = ( new Chroma_Vector_Store( $chroma_url ) )->ping_status();
				$status['store'] = 'chroma';
				return $status;
			case 'pinecone':
				$ok = ( new Pinecone_Vector_Store() )->ping();
				return array(
					'ok'      => $ok,
					'store'   => 'pinecone',
					'url'     => '',
					'api'     => '',
					'message' => $ok
						? __( 'Connected to Pinecone.', 'chathearth' )
						: __( 'Pinecone is not reachable. Check the index host and API key, then save settings.', 'chathearth' ),
				);
			case 'builtin':
			default:
				$ok = ( new Builtin_Vector_Store() )->ping();
				return array(
					'ok'      => $ok,
					'store'   => 'builtin',
					'url'     => '',
					'api'     => '',
					'message' => $ok
						? __( 'Local WordPress-database store is ready. No Chroma server is required.', 'chathearth' )
						: __( 'Vector store is not reachable.', 'chathearth' ),
				);
		}
	}
}
