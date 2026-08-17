<?php
/**
 * Shortcode tests.
 *
 * @package Hello_Cursor
 */

/**
 * Covers the [hello_cursor] shortcode.
 */
class Test_Hello_Cursor_Shortcode extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Hello_Cursor_Plugin::OPTION_NAME );
	}

	public function test_shortcode_is_registered() {
		$this->assertTrue( shortcode_exists( Hello_Cursor_Shortcode::TAG ) );
	}

	public function test_shortcode_renders_the_greeting() {
		$this->assertSame(
			'<p class="hello-cursor-greeting">Hello, Ada!</p>',
			do_shortcode( '[hello_cursor name="Ada"]' )
		);
	}

	public function test_shortcode_escapes_the_name() {
		$output = do_shortcode( '[hello_cursor name="<script>alert(1)</script>"]' );

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	public function test_shortcode_renders_inside_post_content() {
		$post_id = self::factory()->post->create(
			array( 'post_content' => '[hello_cursor name="Ada"]' )
		);

		$this->assertStringContainsString(
			'Hello, Ada!',
			apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) )
		);
	}
}
