<?php
/**
 * Knowledge-base admin REST routes.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Rag\Indexer;
use ChatHearth\Rag\Kb_Repository;
use ChatHearth\Rag\Schema;
use ChatHearth\Rag\Vector_Store_Factory;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Sync, status, and per-entry include/exclude.
 */
final class Kb_Controller {

	public const REST_NAMESPACE = 'chathearth/v1';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/kb/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_sync' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/kb/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/kb/entries',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_entries' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/kb/entries',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_toggle' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/kb/ping',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_ping' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'store'      => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'chroma_url' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
	}

	/**
	 * Require the manage_options capability.
	 *
	 * @return true|WP_Error
	 */
	public function can_manage() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'chathearth_forbidden',
			__( 'You are not allowed to manage the knowledge base.', 'chathearth' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Scan sources and embed a batch.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_sync() {
		Schema::maybe_install();
		$indexer = Indexer::instance();
		$scanned = $indexer->scan_sources();
		$indexed = $indexer->process_queue( 12 );
		$repo    = new Kb_Repository();

		return new WP_REST_Response(
			array(
				'scanned' => $scanned,
				'indexed' => $indexed,
				'counts'  => $repo->status_counts(),
				'pending' => $repo->has_pending(),
			),
			200
		);
	}

	/**
	 * Counts and store ping.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_status() {
		Schema::maybe_install();
		$repo = new Kb_Repository();

		return new WP_REST_Response(
			array(
				'counts'   => $repo->status_counts(),
				'pending'  => $repo->has_pending(),
				'store_ok' => Vector_Store_Factory::make()->ping(),
				'store'    => (string) \ChatHearth\Options::get( 'rag_vector_store', 'builtin' ),
			),
			200
		);
	}

	/**
	 * Paginated entries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_entries( WP_REST_Request $request ) {
		Schema::maybe_install();
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$data   = ( new Kb_Repository() )->paginate( $page, 20, $search );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Toggle include flag.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_toggle( WP_REST_Request $request ) {
		$source_id = sanitize_text_field( (string) $request->get_param( 'source_id' ) );
		$included  = rest_sanitize_boolean( $request->get_param( 'included' ) );
		if ( '' === $source_id ) {
			return new WP_Error(
				'chathearth_kb_invalid',
				__( 'Missing source id.', 'chathearth' ),
				array( 'status' => 400 )
			);
		}

		Indexer::instance()->set_included( $source_id, (bool) $included );
		$entry = ( new Kb_Repository() )->get_by_source( $source_id );
		if ( ! is_array( $entry ) ) {
			return new WP_Error(
				'chathearth_kb_missing',
				__( 'Entry not found.', 'chathearth' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response(
			array(
				'source_id' => $source_id,
				'included'  => ! empty( $entry['included'] ),
				'status'    => (string) $entry['status'],
			),
			200
		);
	}

	/**
	 * Ping the configured vector store.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_ping( WP_REST_Request $request ) {
		$driver = sanitize_key( (string) $request->get_param( 'store' ) );
		$url    = (string) $request->get_param( 'chroma_url' );
		$status = Vector_Store_Factory::ping_status( $driver, $url );

		return new WP_REST_Response(
			$status,
			! empty( $status['ok'] ) ? 200 : 502
		);
	}
}
