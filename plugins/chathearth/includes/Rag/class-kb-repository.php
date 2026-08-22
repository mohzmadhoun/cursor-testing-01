<?php
/**
 * Persistence for knowledge-base entries and chunks.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom-table repository.
 */
final class Kb_Repository {

	/**
	 * Fetch one entry by source id.
	 *
	 * @param string $source_id Source id.
	 * @return array<string, mixed>|null
	 */
	public function get_by_source( string $source_id ): ?array {
		global $wpdb;

		$table = esc_sql( Schema::entries_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internally generated.
				"SELECT * FROM {$table} WHERE source_id = %s",
				$source_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fetch one entry by numeric id.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = esc_sql( Schema::entries_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internally generated.
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert or update a generated document. Returns true when reindex is needed.
	 *
	 * @param array<string, mixed> $document Exported document.
	 */
	public function upsert_document( array $document ): bool {
		global $wpdb;

		$source_id = isset( $document['source_id'] ) ? (string) $document['source_id'] : '';
		$markdown  = isset( $document['markdown'] ) ? (string) $document['markdown'] : '';
		if ( '' === $source_id || '' === $markdown ) {
			return false;
		}

		$hash     = hash( 'sha256', $markdown );
		$existing = $this->get_by_source( $source_id );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$table    = Schema::entries_table();

		$row = array(
			'source_id'    => $source_id,
			'object_type'  => isset( $document['object_type'] ) ? (string) $document['object_type'] : 'post',
			'object_id'    => isset( $document['object_id'] ) ? (int) $document['object_id'] : 0,
			'post_type'    => isset( $document['post_type'] ) ? (string) $document['post_type'] : '',
			'taxonomy'     => isset( $document['taxonomy'] ) ? (string) $document['taxonomy'] : '',
			'title'        => isset( $document['title'] ) ? (string) $document['title'] : '',
			'url'          => isset( $document['url'] ) ? (string) $document['url'] : '',
			'markdown'     => $markdown,
			'content_hash' => $hash,
			'updated_gmt'  => $now,
		);

		if ( is_array( $existing ) ) {
			$included = ! empty( $existing['included'] );
			$changed  = ( $hash !== (string) $existing['content_hash'] );
			$status   = (string) $existing['status'];

			if ( $included && ( $changed || 'indexed' !== $status ) ) {
				$row['status']        = 'pending';
				$row['error_message'] = '';
			} elseif ( ! $included ) {
				$row['status'] = 'excluded';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $row, array( 'id' => (int) $existing['id'] ) );

			return $included && ( $changed || 'indexed' !== $status );
		}

		$row['included'] = 1;
		$row['status']   = 'pending';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $row );

		return true;
	}

	/**
	 * Mark an entry pending (force reindex).
	 *
	 * @param int $id Entry id.
	 */
	public function mark_pending( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::entries_table(),
			array(
				'status'        => 'pending',
				'error_message' => '',
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Set include flag.
	 *
	 * @param string $source_id Source id.
	 * @param bool   $included  Whether the entry is in RAG.
	 */
	public function set_included( string $source_id, bool $included ): void {
		global $wpdb;

		$existing = $this->get_by_source( $source_id );
		if ( ! is_array( $existing ) ) {
			return;
		}

		$data = array(
			'included' => $included ? 1 : 0,
			'status'   => $included ? 'pending' : 'excluded',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::entries_table(),
			$data,
			array( 'id' => (int) $existing['id'] )
		);
	}

	/**
	 * Mark indexed.
	 *
	 * @param int $id Entry id.
	 */
	public function mark_indexed( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::entries_table(),
			array(
				'status'        => 'indexed',
				'error_message' => '',
				'indexed_gmt'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Mark error.
	 *
	 * @param int    $id      Entry id.
	 * @param string $message Error message (no secrets).
	 */
	public function mark_error( int $id, string $message ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::entries_table(),
			array(
				'status'        => 'error',
				'error_message' => mb_substr( $message, 0, 500, 'UTF-8' ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Delete an entry and its chunks.
	 *
	 * @param string $source_id Source id.
	 */
	public function delete_by_source( string $source_id ): void {
		$existing = $this->get_by_source( $source_id );
		if ( ! is_array( $existing ) ) {
			return;
		}

		$this->delete_chunks_for_entry( (int) $existing['id'] );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::entries_table(), array( 'id' => (int) $existing['id'] ) );
	}

	/**
	 * Pending included entries.
	 *
	 * @param int $limit Batch size.
	 * @return list<array<string, mixed>>
	 */
	public function get_pending( int $limit = 10 ): array {
		global $wpdb;

		$table = esc_sql( Schema::entries_table() );
		$limit = max( 1, min( 50, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internally generated.
				"SELECT * FROM {$table} WHERE included = 1 AND status = %s ORDER BY id ASC LIMIT %d",
				'pending',
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Whether any included pending rows remain.
	 */
	public function has_pending(): bool {
		global $wpdb;

		$table = esc_sql( Schema::entries_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internally generated.
				"SELECT COUNT(*) FROM {$table} WHERE included = 1 AND status = %s",
				'pending'
			)
		);

		return $count > 0;
	}

	/**
	 * Counts grouped by status.
	 *
	 * @return array<string, int>
	 */
	public function status_counts(): array {
		global $wpdb;

		$table = esc_sql( Schema::entries_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internally generated.
			"SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status",
			ARRAY_A
		);

		$out = array(
			'pending'  => 0,
			'indexed'  => 0,
			'excluded' => 0,
			'error'    => 0,
		);
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			$key = isset( $row['status'] ) ? (string) $row['status'] : '';
			if ( isset( $out[ $key ] ) ) {
				$out[ $key ] = (int) $row['total'];
			}
		}

		return $out;
	}

	/**
	 * Paginated admin list.
	 *
	 * @param int    $page   Page number.
	 * @param int    $per    Per page.
	 * @param string $search Search string.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function paginate( int $page = 1, int $per = 20, string $search = '' ): array {
		global $wpdb;

		$table  = esc_sql( Schema::entries_table() );
		$page   = max( 1, $page );
		$per    = max( 1, min( 100, $per ) );
		$offset = ( $page - 1 ) * $per;
		$search = trim( $search );

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internally generated.
					"SELECT COUNT(*) FROM {$table} WHERE title LIKE %s OR source_id LIKE %s OR url LIKE %s",
					$like,
					$like,
					$like
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$items = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internally generated.
					"SELECT id, source_id, object_type, post_type, taxonomy, title, url, included, status, updated_gmt, indexed_gmt, error_message FROM {$table} WHERE title LIKE %s OR source_id LIKE %s OR url LIKE %s ORDER BY updated_gmt DESC LIMIT %d OFFSET %d",
					$like,
					$like,
					$like,
					$per,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internally generated.
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$items = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internally generated.
					"SELECT id, source_id, object_type, post_type, taxonomy, title, url, included, status, updated_gmt, indexed_gmt, error_message FROM {$table} ORDER BY updated_gmt DESC LIMIT %d OFFSET %d",
					$per,
					$offset
				),
				ARRAY_A
			);
		}

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Replace chunks for an entry.
	 *
	 * @param int                        $entry_id Entry id.
	 * @param list<array<string, mixed>> $chunks   Chunk rows.
	 */
	public function replace_chunks( int $entry_id, array $chunks ): void {
		global $wpdb;

		$this->delete_chunks_for_entry( $entry_id );
		$table = Schema::chunks_table();

		foreach ( $chunks as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				array(
					'entry_id'    => $entry_id,
					'chunk_id'    => (string) $chunk['chunk_id'],
					'chunk_index' => (int) $chunk['chunk_index'],
					'content'     => (string) $chunk['content'],
					'embedding'   => isset( $chunk['embedding'] ) ? wp_json_encode( $chunk['embedding'] ) : null,
				)
			);
		}
	}

	/**
	 * Delete chunks for an entry.
	 *
	 * @param int $entry_id Entry id.
	 */
	public function delete_chunks_for_entry( int $entry_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::chunks_table(), array( 'entry_id' => $entry_id ) );
	}

	/**
	 * Chunk ids currently stored for an entry.
	 *
	 * @param int $entry_id Entry id.
	 * @return list<string>
	 */
	public function chunk_ids_for_entry( int $entry_id ): array {
		global $wpdb;

		$table = esc_sql( Schema::chunks_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internally generated.
				"SELECT chunk_id FROM {$table} WHERE entry_id = %d",
				$entry_id
			)
		);

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_map( 'strval', $ids );
	}

	/**
	 * Load a chunk by public chunk id.
	 *
	 * @param string $chunk_id Chunk id.
	 * @return array<string, mixed>|null
	 */
	public function get_chunk( string $chunk_id ): ?array {
		global $wpdb;

		$table = esc_sql( Schema::chunks_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internally generated.
				"SELECT * FROM {$table} WHERE chunk_id = %s",
				$chunk_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * All stored embeddings for builtin search.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all_chunk_embeddings(): array {
		global $wpdb;

		$table   = esc_sql( Schema::chunks_table() );
		$entries = esc_sql( Schema::entries_table() );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table names are internally generated.
		$rows = $wpdb->get_results(
			"SELECT c.chunk_id, c.content, c.embedding, e.source_id, e.title, e.url, e.post_type, e.object_type, e.object_id
			FROM {$table} c
			INNER JOIN {$entries} e ON e.id = c.entry_id
			WHERE e.included = 1 AND e.status = 'indexed' AND c.embedding IS NOT NULL",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}
}
