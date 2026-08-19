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
			'chroma'   => __( 'Chroma (self-hosted)', 'chathearth' ),
			'pinecone' => __( 'Pinecone', 'chathearth' ),
		);
	}
}
