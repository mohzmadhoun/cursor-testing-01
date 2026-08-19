<?php
/**
 * In-WordPress cosine-similarity vector store.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses chunk rows already written by the indexer.
 */
final class Builtin_Vector_Store implements Vector_Store_Interface {

	/**
	 * Repository.
	 *
	 * @var Kb_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param Kb_Repository|null $repository Repository.
	 */
	public function __construct( ?Kb_Repository $repository = null ) {
		$this->repository = $repository instanceof Kb_Repository ? $repository : new Kb_Repository();
	}

	/**
	 * Insert or replace records.
	 *
	 * @param array $records Records.
	 */
	public function upsert( array $records ): bool {
		unset( $records );
		// Chunks + embeddings are persisted by the indexer via Kb_Repository::replace_chunks().
		return true;
	}

	/**
	 * Delete records by id.
	 *
	 * @param array $ids Vector ids.
	 */
	public function delete( array $ids ): bool {
		unset( $ids );
		return true;
	}

	/**
	 * Nearest neighbours.
	 *
	 * @param array $embedding Query vector.
	 * @param int   $limit     Max hits.
	 * @return array
	 */
	public function query( array $embedding, int $limit = 5 ): array {
		$limit  = max( 1, min( 20, $limit ) );
		$rows   = $this->repository->all_chunk_embeddings();
		$scored = array();

		foreach ( $rows as $row ) {
			$stored = isset( $row['embedding'] ) ? json_decode( (string) $row['embedding'], true ) : null;
			if ( ! is_array( $stored ) || empty( $stored ) ) {
				continue;
			}
			$vector   = array_map( 'floatval', $stored );
			$score    = self::cosine( $embedding, $vector );
			$scored[] = array(
				'id'      => (string) $row['chunk_id'],
				'score'   => $score,
				'content' => (string) $row['content'],
				'meta'    => array(
					'source_id'   => (string) $row['source_id'],
					'title'       => (string) $row['title'],
					'url'         => (string) $row['url'],
					'post_type'   => (string) $row['post_type'],
					'object_type' => (string) $row['object_type'],
					'object_id'   => (int) $row['object_id'],
				),
			);
		}

		usort(
			$scored,
			static function ( array $a, array $b ): int {
				if ( $a['score'] === $b['score'] ) {
					return 0;
				}
				return ( $a['score'] < $b['score'] ) ? 1 : -1;
			}
		);

		return array_slice( $scored, 0, $limit );
	}

	/**
	 * {@inheritdoc}
	 */
	public function ping(): bool {
		return true;
	}

	/**
	 * Cosine similarity.
	 *
	 * @param array $a Vector a.
	 * @param array $b Vector b.
	 */
	public static function cosine( array $a, array $b ): float {
		$n   = min( count( $a ), count( $b ) );
		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$va   = (float) $a[ $i ];
			$vb   = (float) $b[ $i ];
			$dot += $va * $vb;
			$na  += $va * $va;
			$nb  += $vb * $vb;
		}
		$den = sqrt( $na ) * sqrt( $nb );
		if ( $den <= 0.0 ) {
			return 0.0;
		}

		return $dot / $den;
	}
}
