<?php
/**
 * RAG exporters, stores, grounding, and REST gates.
 *
 * @package ChatHearth
 */

use ChatHearth\Options;
use ChatHearth\Rag\Builtin_Vector_Store;
use ChatHearth\Rag\Chunker;
use ChatHearth\Rag\Html_To_Markdown;
use ChatHearth\Rag\Indexer;
use ChatHearth\Rag\Kb_Repository;
use ChatHearth\Rag\Markdown_Exporter;
use ChatHearth\Rag\Retriever;
use ChatHearth\Rag\Schema;
use ChatHearth\Rag\Site_Grounding;
use ChatHearth\Rest\Cart_Controller;
use ChatHearth\Rest\Kb_Controller;

/**
 * Covers milestone-01 RAG without calling OpenAI.
 */
class Test_ChatHearth_Rag extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Options::OPTION_KEY );
		Schema::install();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . Schema::chunks_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . Schema::entries_table() );
		add_filter( 'chathearth_pre_embed', array( $this, 'fake_embedding' ), 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'chathearth_pre_embed', array( $this, 'fake_embedding' ), 10 );
		parent::tear_down();
	}

	/**
	 * Deterministic 8-d embedding for tests.
	 *
	 * @param mixed  $pre  Existing value.
	 * @param string $text Text.
	 * @return list<float>
	 */
	public function fake_embedding( $pre, $text ) {
		unset( $pre );
		$vec    = array_fill( 0, 8, 0.0 );
		$text   = (string) $text;
		$length = strlen( $text );
		for ( $i = 0; $i < $length; $i++ ) {
			$vec[ $i % 8 ] += ord( $text[ $i ] ) / 255.0;
		}
		return $vec;
	}

	public function test_html_to_markdown_keeps_links_and_headings() {
		$html = new Html_To_Markdown();
		$out  = $html->convert( '<h2>Hello</h2><p>See <a href="https://example.com/x">docs</a>.</p>' );

		$this->assertStringContainsString( '## Hello', $out );
		$this->assertStringContainsString( '[docs](https://example.com/x)', $out );
	}

	public function test_chunker_splits_with_overlap() {
		$text   = str_repeat( 'abcdefghij', 80 );
		$chunks = ( new Chunker() )->split( $text, 240, 40 );

		$this->assertGreaterThan( 1, count( $chunks ) );
		$this->assertSame( 240, strlen( $chunks[0] ) );
	}

	public function test_post_export_includes_title_url_and_body() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Shipping Policy',
				'post_content' => '<p>We ship worldwide in 5 days.</p>',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		$doc = ( new Markdown_Exporter() )->export_post( $post_id );
		$this->assertIsArray( $doc );
		$this->assertSame( 'post:' . $post_id, $doc['source_id'] );
		$this->assertStringContainsString( 'Shipping Policy', $doc['markdown'] );
		$this->assertStringContainsString( 'We ship worldwide in 5 days.', $doc['markdown'] );
		$this->assertNotEmpty( $doc['url'] );
	}

	public function test_term_export_and_hash_skip() {
		$term = self::factory()->term->create_and_get(
			array(
				'taxonomy'    => 'category',
				'name'        => 'Jackets',
				'description' => 'Warm outerwear',
			)
		);

		$exporter = new Markdown_Exporter();
		$doc      = $exporter->export_term( (int) $term->term_id, 'category' );
		$this->assertIsArray( $doc );
		$this->assertStringContainsString( 'Jackets', $doc['markdown'] );

		$repo = new Kb_Repository();
		$this->assertTrue( $repo->upsert_document( $doc ) );
		$entry = $repo->get_by_source( $doc['source_id'] );
		$this->assertIsArray( $entry );
		$repo->mark_indexed( (int) $entry['id'] );

		$this->assertFalse( $repo->upsert_document( $doc ) );
	}

	public function test_builtin_store_ranks_similar_text_higher() {
		$repo = new Kb_Repository();
		$doc  = array(
			'source_id'   => 'post:9001',
			'object_type' => 'post',
			'object_id'   => 9001,
			'post_type'   => 'page',
			'taxonomy'    => '',
			'title'       => 'Returns',
			'url'         => 'https://example.test/returns',
			'markdown'    => 'Refunds are issued within 14 days of return.',
		);
		$repo->upsert_document( $doc );
		$entry = $repo->get_by_source( 'post:9001' );
		$this->assertIsArray( $entry );

		Indexer::instance()->index_entry( $entry );

		$store = new Builtin_Vector_Store( $repo );
		$hits  = $store->query( $this->fake_embedding( null, 'How do refunds work?' ), 3 );
		$this->assertNotEmpty( $hits );
		$this->assertSame( 'post-9001-c0', $hits[0]['id'] );
	}

	public function test_grounding_is_injected_when_rag_is_disabled() {
		update_option( 'blogname', 'Northwind Apparel' );
		$grounding = new Site_Grounding();
		$prompt    = $grounding->inject( 'Be witty.', 'What is the capital of France?', array() );

		$this->assertStringContainsString( 'Northwind Apparel', $prompt );
		$this->assertStringContainsString( 'Hard rules', $prompt );
		$this->assertStringContainsString( 'website-only scope', $prompt );
		$this->assertFalse( Options::is_rag_enabled() );
		$this->assertStringNotContainsString( 'Retrieved knowledge', $prompt );
	}

	public function test_retriever_appends_hits_when_rag_enabled() {
		update_option(
			Options::OPTION_KEY,
			array_merge(
				Options::defaults(),
				array( 'rag_enabled' => true )
			)
		);

		$repo = new Kb_Repository();
		$repo->upsert_document(
			array(
				'source_id'   => 'post:42',
				'object_type' => 'post',
				'object_id'   => 42,
				'post_type'   => 'page',
				'taxonomy'    => '',
				'title'       => 'Contact',
				'url'         => 'https://example.test/contact',
				'markdown'    => 'Email support@example.test for help with orders.',
			)
		);
		$entry = $repo->get_by_source( 'post:42' );
		Indexer::instance()->index_entry( $entry );

		$prompt = Retriever::instance()->inject( 'Base prompt', 'How can I email support?', array() );
		$this->assertStringContainsString( 'Retrieved knowledge', $prompt );
		$this->assertStringContainsString( 'support@example.test', $prompt );
		$sources = Retriever::instance()->last_sources();
		$this->assertNotEmpty( $sources );
		$this->assertSame( 'https://example.test/contact', $sources[0]['url'] );
	}

	public function test_kb_rest_requires_manage_options() {
		$controller = new Kb_Controller();
		$result     = $controller->can_manage();
		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_cart_rest_rejects_missing_nonce() {
		$controller = new Cart_Controller();
		$request    = new WP_REST_Request( 'POST', '/chathearth/v1/cart' );
		$result     = $controller->permission_check( $request );
		$this->assertWPError( $result );
		$this->assertSame( 'chathearth_invalid_nonce', $result->get_error_code() );
	}

	public function test_rag_settings_sanitize_store_and_limits() {
		$settings = Options::sanitize(
			array(
				'rag_vector_store' => 'pinecone',
				'rag_enabled'      => '1',
				'rag_top_k'        => 99,
				'rag_chunk_size'   => 10,
				'rag_post_types'   => array( 'page', 'not valid!' ),
			)
		);

		$this->assertTrue( $settings['rag_enabled'] );
		$this->assertSame( 'pinecone', $settings['rag_vector_store'] );
		$this->assertSame( 12, $settings['rag_top_k'] );
		$this->assertSame( 400, $settings['rag_chunk_size'] );
		$this->assertSame( array( 'page', 'notvalid' ), $settings['rag_post_types'] );
	}

	public function test_rest_routes_include_kb_and_cart() {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$routes = $wp_rest_server->get_routes();

		$this->assertArrayHasKey( '/chathearth/v1/kb/sync', $routes );
		$this->assertArrayHasKey( '/chathearth/v1/cart', $routes );
	}
}
