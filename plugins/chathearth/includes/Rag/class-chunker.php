<?php
/**
 * Split markdown into overlapping embedding chunks.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Character-based chunker with overlap.
 */
final class Chunker {

	/**
	 * Split text.
	 *
	 * @param string $text    Source markdown.
	 * @param int    $size    Max characters per chunk.
	 * @param int    $overlap Overlap characters.
	 * @return list<string>
	 */
	public function split( string $text, int $size = 1800, int $overlap = 200 ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array();
		}

		$size    = max( 200, $size );
		$overlap = max( 0, min( $overlap, $size - 1 ) );

		$length = mb_strlen( $text, 'UTF-8' );
		if ( $length <= $size ) {
			return array( $text );
		}

		$chunks = array();
		$start  = 0;
		while ( $start < $length ) {
			$chunks[] = mb_substr( $text, $start, $size, 'UTF-8' );
			$next     = $start + ( $size - $overlap );
			if ( $next <= $start ) {
				break;
			}
			$start = $next;
		}

		return $chunks;
	}
}
