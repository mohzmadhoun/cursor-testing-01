<?php
/**
 * Plugin bootstrap.
 *
 * @package Mzm_Current_Year
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires MZM Current Year into WordPress.
 */
class Mzm_Current_Year_Plugin {

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	const SHORTCODE = 'mzm-current-year';

	/**
	 * Shared instance.
	 *
	 * @var Mzm_Current_Year_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared instance.
	 *
	 * @return Mzm_Current_Year_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers every hook the plugin uses.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_shortcode' ) );
	}

	/**
	 * Registers the current-year shortcode.
	 *
	 * @return void
	 */
	public function register_shortcode() {
		load_plugin_textdomain( 'mzm-current-year', false, dirname( plugin_basename( MZM_CURRENT_YEAR_FILE ) ) . '/languages' );
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	/**
	 * Renders the current year using the WordPress timezone.
	 *
	 * @return string Four-digit current year.
	 */
	public function render_shortcode() {
		return esc_html( wp_date( 'Y' ) );
	}
}
