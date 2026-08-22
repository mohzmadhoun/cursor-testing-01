<?php
/**
 * Build the WordPress-database vector store.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Factory for Vector_Store_Interface implementations.
 *
 * ChatHearth stores embeddings in this site's WordPress database. Extra
 * processes (Python, Chroma) and third-party indexes (Pinecone) are not used.
 */
final class Vector_Store_Factory {

	/**
	 * Create the in-WordPress store.
	 */
	public static function make(): Vector_Store_Interface {
		$store    = new Builtin_Vector_Store();
		$filtered = apply_filters( 'chathearth_vector_store', $store, 'builtin' );
		if ( $filtered instanceof Vector_Store_Interface ) {
			return $filtered;
		}

		return $store;
	}

	/**
	 * Ping the WordPress-database store.
	 *
	 * @return array<string, mixed>
	 */
	public static function ping_status(): array {
		$ok = self::make()->ping();

		return array(
			'ok'      => $ok,
			'store'   => 'builtin',
			'url'     => '',
			'api'     => '',
			'message' => $ok
				? __( 'The knowledge base is stored in this WordPress database. No extra server is required.', 'chathearth' )
				: __( 'The knowledge base tables are not available.', 'chathearth' ),
		);
	}
}
