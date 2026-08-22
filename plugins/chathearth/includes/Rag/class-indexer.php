<?php
/**
 * Scan, incremental hooks, and embedding queue.
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
 * Keeps markdown + vectors in sync with site content.
 */
final class Indexer {

	public const CRON_HOOK = 'chathearth_process_kb_queue';

	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Skip nested hooks while scanning.
	 *
	 * @var bool
	 */
	private $quiet = false;

	/**
	 * Instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register content hooks and cron.
	 */
	public function register(): void {
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
		add_action( 'trashed_post', array( $this, 'on_delete_post' ) );
		add_action( 'created_term', array( $this, 'on_save_term' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'on_save_term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_delete_term' ), 10, 4 );
		add_action( 'updated_option_blogname', array( $this, 'on_site_identity' ) );
		add_action( 'updated_option_blogdescription', array( $this, 'on_site_identity' ) );
		add_action( 'update_option_chathearth_settings', array( $this, 'on_settings_updated' ), 10, 2 );
		add_action( self::CRON_HOOK, array( $this, 'cron_tick' ) );
		add_action( 'init', array( $this, 'ensure_cron' ) );

		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'woocommerce_new_product', array( $this, 'on_product_id' ) );
			add_action( 'woocommerce_update_product', array( $this, 'on_product_id' ) );
			add_action( 'woocommerce_delete_product', array( $this, 'on_delete_post' ) );
		}
	}

	/**
	 * Hourly safety-net cron.
	 */
	public function ensure_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback (no return value).
	 */
	public function cron_tick(): void {
		$this->process_queue();
	}

	/**
	 * Post saved.
	 *
	 * @param int   $post_id Post id.
	 * @param mixed $post    Post.
	 * @param bool  $update  Whether this is an update.
	 */
	public function on_save_post( $post_id, $post, $update ): void {
		unset( $update );
		if ( $this->quiet ) {
			return;
		}
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			$this->remove_source( 'post:' . $post_id );
			return;
		}

		$allowed = Options::rag_post_types();
		if ( ! in_array( $post->post_type, $allowed, true ) ) {
			return;
		}

		$this->upsert_post( $post_id );
		$this->schedule_queue();
	}

	/**
	 * Post deleted or trashed.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_delete_post( $post_id ): void {
		if ( $this->quiet ) {
			return;
		}
		$this->remove_source( 'post:' . (int) $post_id );
	}

	/**
	 * WooCommerce product id hook.
	 *
	 * @param int $product_id Product id.
	 */
	public function on_product_id( $product_id ): void {
		$this->on_save_post( (int) $product_id, get_post( (int) $product_id ), true );
	}

	/**
	 * Term saved.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_save_term( $term_id, $tt_id, $taxonomy ): void {
		unset( $tt_id );
		if ( $this->quiet ) {
			return;
		}
		if ( ! in_array( (string) $taxonomy, Options::rag_taxonomies(), true ) ) {
			return;
		}
		$this->upsert_term( (int) $term_id, (string) $taxonomy );
		$this->schedule_queue();
	}

	/**
	 * Term deleted.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 * @param mixed  $deleted  Deleted term object.
	 */
	public function on_delete_term( $term_id, $tt_id, $taxonomy, $deleted ): void {
		unset( $tt_id, $taxonomy, $deleted );
		if ( $this->quiet ) {
			return;
		}
		$this->remove_source( 'term:' . (int) $term_id );
	}

	/**
	 * Site title/tagline changed.
	 */
	public function on_site_identity(): void {
		if ( $this->quiet || ! Options::rag_include_site_identity() ) {
			return;
		}
		$exporter = new Markdown_Exporter();
		$doc      = $exporter->export_site_identity();
		$exporter->write_file( $doc );
		( new Kb_Repository() )->upsert_document( $doc );
		delete_transient( Site_Grounding::TRANSIENT_KEY );
		$this->schedule_queue();
	}

	/**
	 * Re-scan when selected sources or RAG toggle change.
	 *
	 * @param mixed $old     Old value.
	 * @param mixed $updated New value.
	 */
	public function on_settings_updated( $old, $updated ): void {
		if ( $this->quiet ) {
			return;
		}
		$old     = is_array( $old ) ? $old : array();
		$updated = is_array( $updated ) ? $updated : array();

		$was = ! empty( $updated['rag_enabled'] );
		if ( ! $was ) {
			return;
		}

		$keys    = array( 'rag_enabled', 'rag_post_types', 'rag_taxonomies', 'rag_include_site_identity', 'rag_include_woocommerce', 'rag_vector_store' );
		$changed = false;
		foreach ( $keys as $key ) {
			$before = wp_json_encode( $old[ $key ] ?? null );
			$after  = wp_json_encode( $updated[ $key ] ?? null );
			if ( $before !== $after ) {
				$changed = true;
				break;
			}
		}
		if ( $changed ) {
			$this->scan_sources();
			$this->schedule_queue();
		}
	}

	/**
	 * Export selected sources into the KB table (no embeddings yet).
	 *
	 * @return int Number of documents written.
	 */
	public function scan_sources(): int {
		$this->quiet = true;
		Schema::maybe_install();

		$exporter = new Markdown_Exporter();
		$repo     = new Kb_Repository();
		$count    = 0;

		if ( Options::rag_include_site_identity() ) {
			$doc = $exporter->export_site_identity();
			$exporter->write_file( $doc );
			$repo->upsert_document( $doc );
			++$count;
		}

		if ( Options::rag_include_woocommerce() ) {
			$woo = $exporter->export_woocommerce();
			if ( is_array( $woo ) ) {
				$exporter->write_file( $woo );
				$repo->upsert_document( $woo );
				++$count;
			}
		}

		foreach ( Options::rag_post_types() as $post_type ) {
			$paged       = 1;
			$batch_count = 0;
			do {
				$ids = get_posts(
					array(
						'post_type'              => $post_type,
						'post_status'            => 'publish',
						'posts_per_page'         => 100,
						'paged'                  => $paged,
						'fields'                 => 'ids',
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);
				if ( empty( $ids ) ) {
					break;
				}
				foreach ( $ids as $id ) {
					$doc = $exporter->export_post( (int) $id );
					if ( ! is_array( $doc ) ) {
						continue;
					}
					$exporter->write_file( $doc );
					$repo->upsert_document( $doc );
					++$count;
				}
				$batch_count = count( $ids );
				++$paged;
			} while ( 100 === $batch_count );
		}

		foreach ( Options::rag_taxonomies() as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$doc = $exporter->export_term( (int) $term->term_id, $taxonomy );
				if ( ! is_array( $doc ) ) {
					continue;
				}
				$exporter->write_file( $doc );
				$repo->upsert_document( $doc );
				++$count;
			}
		}

		delete_transient( Site_Grounding::TRANSIENT_KEY );
		$this->quiet = false;

		return $count;
	}

	/**
	 * Embed a batch of pending entries.
	 *
	 * @param int $limit Max entries this tick.
	 * @return int Processed count.
	 */
	public function process_queue( int $limit = 8 ): int {
		Schema::maybe_install();
		$repo  = new Kb_Repository();
		$limit = max( 1, min( 25, $limit ) );
		$items = $repo->get_pending( $limit );
		$done  = 0;

		foreach ( $items as $entry ) {
			$this->index_entry( $entry );
			++$done;
		}

		if ( $repo->has_pending() ) {
			$this->schedule_queue( 15 );
		}

		return $done;
	}

	/**
	 * Index a single KB row.
	 *
	 * @param array<string, mixed> $entry Entry row.
	 */
	public function index_entry( array $entry ): void {
		$repo = new Kb_Repository();
		$id   = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
		if ( $id <= 0 ) {
			return;
		}

		if ( empty( $entry['included'] ) ) {
			$this->drop_vectors_for_entry( $entry );
			$repo->set_included( (string) $entry['source_id'], false );
			return;
		}

		$markdown = isset( $entry['markdown'] ) ? (string) $entry['markdown'] : '';
		$chunks   = ( new Chunker() )->split(
			$markdown,
			(int) Options::get( 'rag_chunk_size', 1800 ),
			200
		);
		if ( empty( $chunks ) ) {
			$repo->mark_error( $id, 'Empty content.' );
			return;
		}

		$embedder = new Embedding_Client();
		$vectors  = $embedder->embed_many( $chunks );
		if ( is_wp_error( $vectors ) ) {
			$repo->mark_error( $id, $vectors->get_error_message() );
			return;
		}

		$old_ids = $repo->chunk_ids_for_entry( $id );
		$store   = Vector_Store_Factory::make();
		if ( ! empty( $old_ids ) ) {
			$store->delete( $old_ids );
		}

		$records    = array();
		$chunk_rows = array();
		$source_id  = (string) $entry['source_id'];
		foreach ( $chunks as $i => $text ) {
			$chunk_id = $this->chunk_id( $source_id, $i );
			$vector   = isset( $vectors[ $i ] ) ? $vectors[ $i ] : array();
			if ( empty( $vector ) ) {
				$repo->mark_error( $id, 'Missing embedding for a chunk.' );
				return;
			}
			$meta         = array(
				'source_id'   => $source_id,
				'title'       => (string) $entry['title'],
				'url'         => (string) $entry['url'],
				'post_type'   => (string) $entry['post_type'],
				'object_type' => (string) $entry['object_type'],
				'object_id'   => (int) $entry['object_id'],
			);
			$records[]    = array(
				'id'        => $chunk_id,
				'embedding' => $vector,
				'content'   => $text,
				'meta'      => $meta,
			);
			$chunk_rows[] = array(
				'chunk_id'    => $chunk_id,
				'chunk_index' => $i,
				'content'     => $text,
				'embedding'   => $vector,
			);
		}

		$repo->replace_chunks( $id, $chunk_rows );
		if ( ! $store->upsert( $records ) ) {
			Logger::error( 'Vector upsert failed.', array( 'source' => $source_id ) );
			$repo->mark_error( $id, 'Vector store upsert failed.' );
			return;
		}

		$repo->mark_indexed( $id );
	}

	/**
	 * Include/exclude from admin.
	 *
	 * @param string $source_id Source id.
	 * @param bool   $included  Included.
	 */
	public function set_included( string $source_id, bool $included ): void {
		$repo = new Kb_Repository();
		$repo->set_included( $source_id, $included );
		$entry = $repo->get_by_source( $source_id );
		if ( ! is_array( $entry ) ) {
			return;
		}
		if ( ! $included ) {
			$this->drop_vectors_for_entry( $entry );
			return;
		}
		$this->schedule_queue();
	}

	/**
	 * Schedule a near-term queue run.
	 *
	 * @param int $delay Seconds.
	 */
	public function schedule_queue( int $delay = 5 ): void {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $next && $next <= time() + $delay ) {
			return;
		}
		wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
	}

	/**
	 * Export one post into the table.
	 *
	 * @param int $post_id Post id.
	 */
	private function upsert_post( int $post_id ): void {
		$exporter = new Markdown_Exporter();
		$doc      = $exporter->export_post( $post_id );
		if ( ! is_array( $doc ) ) {
			return;
		}
		$exporter->write_file( $doc );
		( new Kb_Repository() )->upsert_document( $doc );
	}

	/**
	 * Export one term into the table.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	private function upsert_term( int $term_id, string $taxonomy ): void {
		$exporter = new Markdown_Exporter();
		$doc      = $exporter->export_term( $term_id, $taxonomy );
		if ( ! is_array( $doc ) ) {
			return;
		}
		$exporter->write_file( $doc );
		( new Kb_Repository() )->upsert_document( $doc );
	}

	/**
	 * Remove source from files, chunks, and remote store.
	 *
	 * @param string $source_id Source id.
	 */
	private function remove_source( string $source_id ): void {
		$repo  = new Kb_Repository();
		$entry = $repo->get_by_source( $source_id );
		if ( is_array( $entry ) ) {
			$this->drop_vectors_for_entry( $entry );
		}
		( new Markdown_Exporter() )->delete_file( $source_id );
		$repo->delete_by_source( $source_id );
	}

	/**
	 * Drop vectors for one entry.
	 *
	 * @param array<string, mixed> $entry Entry.
	 */
	private function drop_vectors_for_entry( array $entry ): void {
		$repo = new Kb_Repository();
		$id   = (int) $entry['id'];
		$ids  = $repo->chunk_ids_for_entry( $id );
		if ( ! empty( $ids ) ) {
			Vector_Store_Factory::make()->delete( $ids );
		}
		$repo->delete_chunks_for_entry( $id );
	}

	/**
	 * Stable chunk id.
	 *
	 * @param string $source_id Source id.
	 * @param int    $index     Chunk index.
	 */
	private function chunk_id( string $source_id, int $index ): string {
		$safe = strtolower( preg_replace( '/[^a-zA-Z0-9._-]+/', '-', $source_id ) ?? $source_id );
		return $safe . '-c' . $index;
	}
}
