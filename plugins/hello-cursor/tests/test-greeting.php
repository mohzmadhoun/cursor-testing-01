<?php
/**
 * Greeting tests.
 *
 * @package Hello_Cursor
 */

/**
 * Covers Hello_Cursor_Plugin::greeting() and the stored settings.
 */
class Test_Hello_Cursor_Greeting extends WP_UnitTestCase {

	/**
	 * Plugin instance under test.
	 *
	 * @var Hello_Cursor_Plugin
	 */
	private $plugin;

	public function set_up() {
		parent::set_up();
		$this->plugin = hello_cursor();
		delete_option( Hello_Cursor_Plugin::OPTION_NAME );
	}

	public function test_plugin_is_loaded() {
		$this->assertTrue( class_exists( 'Hello_Cursor_Plugin' ) );
		$this->assertInstanceOf( Hello_Cursor_Plugin::class, $this->plugin );
	}

	public function test_default_greeting_uses_the_site_title() {
		$this->assertSame(
			'Hello, ' . get_bloginfo( 'name' ) . '!',
			$this->plugin->greeting()
		);
	}

	public function test_greeting_accepts_a_name() {
		$this->assertSame( 'Hello, Ada!', $this->plugin->greeting( 'Ada' ) );
	}

	public function test_greeting_trims_whitespace_and_falls_back() {
		$this->assertSame( 'Hello, Ada!', $this->plugin->greeting( '  Ada  ' ) );
		$this->assertSame(
			'Hello, ' . get_bloginfo( 'name' ) . '!',
			$this->plugin->greeting( '   ' )
		);
	}

	public function test_greeting_word_comes_from_the_settings() {
		update_option( Hello_Cursor_Plugin::OPTION_NAME, array( 'greeting' => 'Welcome' ) );

		$this->assertSame( 'Welcome, Ada!', $this->plugin->greeting( 'Ada' ) );
	}

	public function test_settings_fall_back_to_defaults_for_missing_keys() {
		update_option( Hello_Cursor_Plugin::OPTION_NAME, array() );

		$this->assertSame( Hello_Cursor_Plugin::default_settings(), $this->plugin->get_settings() );
	}

	public function test_settings_are_sanitized() {
		$settings = new Hello_Cursor_Settings( $this->plugin );

		$this->assertSame(
			array( 'greeting' => 'Hi there' ),
			$settings->sanitize( array( 'greeting' => '<b>Hi there</b>' ) )
		);
		$this->assertSame(
			Hello_Cursor_Plugin::default_settings(),
			$settings->sanitize( 'not an array' )
		);
	}
}
