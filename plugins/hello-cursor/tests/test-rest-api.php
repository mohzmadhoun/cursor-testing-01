<?php
/**
 * REST route tests.
 *
 * @package Hello_Cursor
 */

/**
 * Covers /wp-json/hello-cursor/v1/greeting.
 *
 * @group restapi
 */
class Test_Hello_Cursor_REST_API extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Hello_Cursor_Plugin::OPTION_NAME );

		// The REST server is built once per request, so rebuild it per test.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_route_is_registered() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/hello-cursor/v1/greeting', $routes );
	}

	public function test_greeting_is_returned_for_a_name() {
		$request = new WP_REST_Request( 'GET', '/hello-cursor/v1/greeting' );
		$request->set_param( 'name', 'Ada' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'greeting' => 'Hello, Ada!',
				'name'     => 'Ada',
			),
			$response->get_data()
		);
	}

	public function test_greeting_word_from_settings_is_used() {
		update_option( Hello_Cursor_Plugin::OPTION_NAME, array( 'greeting' => 'Welcome' ) );

		$request = new WP_REST_Request( 'GET', '/hello-cursor/v1/greeting' );
		$request->set_param( 'name', 'Ada' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 'Welcome, Ada!', $response->get_data()['greeting'] );
	}
}
