<?php
/**
 * Plugin tests.
 *
 * @package Mzm_Current_Year
 */

class Test_Mzm_Current_Year_Plugin extends WP_UnitTestCase {

	public function test_plugin_is_loaded() {
		$this->assertTrue( class_exists( 'Mzm_Current_Year_Plugin' ) );
		$this->assertInstanceOf( Mzm_Current_Year_Plugin::class, mzm_current_year() );
	}

	public function test_shortcode_is_registered() {
		$this->assertTrue( shortcode_exists( Mzm_Current_Year_Plugin::SHORTCODE ) );
	}

	public function test_shortcode_renders_current_year() {
		$this->assertSame( wp_date( 'Y' ), do_shortcode( '[mzm-current-year]' ) );
	}

	public function test_shortcode_renders_in_post_content() {
		$post_id = self::factory()->post->create(
			array( 'post_content' => 'Copyright [mzm-current-year]' )
		);

		$this->assertStringContainsString(
			'Copyright ' . wp_date( 'Y' ),
			apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) )
		);
	}
}
