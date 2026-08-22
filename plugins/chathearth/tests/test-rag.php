<?php
/**
 * RAG exporters, stores, grounding, and REST gates.
 *
 * @package ChatHearth
 */

use ChatHearth\Commerce\Product_Catalog;
use ChatHearth\Options;
use ChatHearth\Rag\Builtin_Vector_Store;
use ChatHearth\Rag\Chunker;
use ChatHearth\Rag\Current_Page;
use ChatHearth\Rag\Html_To_Markdown;
use ChatHearth\Rag\Indexer;
use ChatHearth\Rag\Kb_Repository;
use ChatHearth\Rag\Markdown_Exporter;
use ChatHearth\Rag\Retriever;
use ChatHearth\Rag\Schema;
use ChatHearth\Rag\Site_Grounding;
use ChatHearth\Rag\Vector_Store_Factory;
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
		$chunks  = esc_sql( Schema::chunks_table() );
		$entries = esc_sql( Schema::entries_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$chunks}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$entries}" );
		add_filter( 'chathearth_pre_embed', array( $this, 'fake_embedding' ), 10, 2 );
		Current_Page::instance()->reset();
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

	public function test_current_page_is_injected_for_a_published_post() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Overnight Shipping',
				'post_content' => '<p>Orders placed before 2pm leave the same day.</p>',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		$page = Current_Page::instance();
		$page->capture( $post_id, 'post', '', '' );
		$prompt = $page->inject( 'Base prompt', 'What does this page say about shipping?', array() );

		$this->assertStringContainsString( '## Current page', $prompt );
		$this->assertStringContainsString( 'Overnight Shipping', $prompt );
		$this->assertStringContainsString( 'Orders placed before 2pm leave the same day.', $prompt );
		$source = $page->source();
		$this->assertIsArray( $source );
		$this->assertSame( get_permalink( $post_id ), $source['url'] );
	}

	public function test_current_page_ignores_drafts_and_off_site_urls() {
		$draft_id = self::factory()->post->create(
			array(
				'post_title'   => 'Secret Draft',
				'post_content' => '<p>Unpublished coupon CODE123.</p>',
				'post_status'  => 'draft',
			)
		);

		$page = Current_Page::instance();
		$page->capture( $draft_id, 'post', '', 'https://evil.example/phishing' );
		$prompt = $page->inject( 'Base prompt', 'Tell me about this page', array() );

		$this->assertStringNotContainsString( 'Secret Draft', $prompt );
		$this->assertStringNotContainsString( 'CODE123', $prompt );
		$this->assertStringNotContainsString( 'evil.example', $prompt );
		$this->assertNull( $page->source() );

		$protected_id = self::factory()->post->create(
			array(
				'post_title'    => 'Members only',
				'post_content'  => '<p>Hidden warehouse address.</p>',
				'post_status'   => 'publish',
				'post_password' => 's3cret',
			)
		);
		$page->capture( $protected_id, 'post', '', '' );
		$prompt = $page->inject( 'Base prompt', 'Tell me about this page', array() );
		$this->assertStringNotContainsString( 'Hidden warehouse address', $prompt );
	}

	public function test_current_page_injects_a_public_term() {
		$term = self::factory()->term->create_and_get(
			array(
				'taxonomy'    => 'category',
				'name'        => 'Outerwear',
				'description' => 'Coats and jackets for cold weather.',
			)
		);

		$page = Current_Page::instance();
		$page->capture( (int) $term->term_id, 'term', 'category', '' );
		$prompt = $page->inject( 'Base prompt', 'What is this category?', array() );

		$this->assertStringContainsString( 'Outerwear', $prompt );
		$this->assertStringContainsString( 'Coats and jackets for cold weather.', $prompt );
	}

	public function test_current_page_resolves_a_same_site_permalink() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Returns Desk',
				'post_content' => '<p>Bring your receipt within 30 days.</p>',
				'post_status'  => 'publish',
			)
		);
		$url     = (string) get_permalink( $post_id );

		$page = Current_Page::instance();
		$page->capture( 0, '', '', $url );
		$prompt = $page->inject( 'Base prompt', 'Tell me about this page', array() );

		$this->assertStringContainsString( 'Returns Desk', $prompt );
		$this->assertStringContainsString( 'Bring your receipt within 30 days.', $prompt );
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

	public function test_product_name_mention_matching() {
		$this->assertTrue( Product_Catalog::message_mentions_name( 'Compare Shirt vs Jacket', 'Shirt' ) );
		$this->assertTrue( Product_Catalog::message_mentions_name( 'Compare Shirt vs Jacket', 'Jacket' ) );
		$this->assertFalse( Product_Catalog::message_mentions_name( 'I like tshirts', 'Shirt' ) );
		$this->assertFalse( Product_Catalog::message_mentions_name( 'Hello there', 'Hi' ) );
		$this->assertNull( ( new Product_Catalog() )->get_public_product( 1 ) );
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
				'rag_vector_store' => 'chroma',
				'rag_enabled'      => '1',
				'rag_top_k'        => 99,
				'rag_chunk_size'   => 10,
				'rag_post_types'   => array( 'page', 'not valid!' ),
			)
		);

		$this->assertTrue( $settings['rag_enabled'] );
		$this->assertSame( 'builtin', $settings['rag_vector_store'] );
		$this->assertArrayNotHasKey( 'rag_chroma_url', $settings );
		$this->assertArrayNotHasKey( 'rag_pinecone_api_key', $settings );
		$this->assertSame( 12, $settings['rag_top_k'] );
		$this->assertSame( 400, $settings['rag_chunk_size'] );
		$this->assertSame( array( 'page', 'notvalid' ), $settings['rag_post_types'] );
	}

	public function test_maybe_use_wordpress_vector_store_strips_external_keys() {
		update_option(
			Options::OPTION_KEY,
			array(
				'rag_vector_store'     => 'chroma',
				'rag_chroma_url'       => 'http://127.0.0.1:8000',
				'rag_pinecone_api_key' => 'secret',
			)
		);

		Options::maybe_use_wordpress_vector_store();

		$stored = get_option( Options::OPTION_KEY );
		$this->assertIsArray( $stored );
		$this->assertSame( 'builtin', $stored['rag_vector_store'] );
		$this->assertArrayNotHasKey( 'rag_chroma_url', $stored );
		$this->assertArrayNotHasKey( 'rag_pinecone_api_key', $stored );
	}

	public function test_rest_routes_include_kb_and_cart() {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$routes = $wp_rest_server->get_routes();

		$this->assertArrayHasKey( '/chathearth/v1/kb/sync', $routes );
		$this->assertArrayHasKey( '/chathearth/v1/cart', $routes );
	}

	public function test_builtin_ping_status_is_ready() {
		$status = Vector_Store_Factory::ping_status();

		$this->assertTrue( $status['ok'] );
		$this->assertSame( 'builtin', $status['store'] );
		$this->assertStringContainsString( 'WordPress database', $status['message'] );
	}
}
