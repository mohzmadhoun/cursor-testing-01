<?php
/**
 * Vector store contract.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upsert / delete / query embeddings.
 */
interface Vector_Store_Interface {

	/**
	 * Insert or replace records.
	 *
	 * @param list<array{id:string,embedding:list<float>,content:string,meta:array<string, mixed>}> $records Records.
	 */
	public function upsert( array $records ): bool;

	/**
	 * Delete records by id.
	 *
	 * @param array $ids Vector ids.
	 */
	public function delete( array $ids ): bool;

	/**
	 * Nearest neighbours.
	 *
	 * @param array $embedding Query vector.
	 * @param int   $limit     Max hits.
	 * @return list<array{id:string,score:float,content:string,meta:array<string, mixed>}>
	 */
	public function query( array $embedding, int $limit = 5 ): array;

	/**
	 * Connectivity check.
	 */
	public function ping(): bool;
}
