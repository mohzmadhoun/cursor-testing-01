<?php
/**
 * Plugin bootstrap.
 *
 * @package Hello_Cursor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's features into WordPress and owns its stored settings.
 */
class Hello_Cursor_Plugin {

	/**
	 * Option name holding the plugin settings.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'hello_cursor_settings';

	/**
	 * Shared instance.
	 *
	 * @var Hello_Cursor_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings screen.
	 *
	 * @var Hello_Cursor_Settings
	 */
	private $settings;

	/**
	 * Shortcode handler.
	 *
	 * @var Hello_Cursor_Shortcode
	 */
	private $shortcode;

	/**
	 * REST controller.
	 *
	 * @var Hello_Cursor_REST_Controller
	 */
	private $rest_controller;

	/**
	 * Builds the feature objects.
	 */
	private function __construct() {
		$this->settings        = new Hello_Cursor_Settings( $this );
		$this->shortcode       = new Hello_Cursor_Shortcode( $this );
		$this->rest_controller = new Hello_Cursor_REST_Controller( $this );
	}

	/**
	 * Returns the shared instance.
	 *
	 * @return Hello_Cursor_Plugin
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
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		$this->settings->register();
		$this->shortcode->register();
		$this->rest_controller->register();
	}

	/**
	 * Loads the plugin translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'hello-cursor', false, dirname( plugin_basename( HELLO_CURSOR_FILE ) ) . '/languages' );
	}

	/**
	 * Enqueues the front-end assets.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		wp_enqueue_style(
			'hello-cursor',
			HELLO_CURSOR_URL . 'assets/css/hello-cursor.css',
			array(),
			HELLO_CURSOR_VERSION
		);
	}

	/**
	 * Returns the settings, falling back to the defaults for missing keys.
	 *
	 * @return array<string, string> Plugin settings.
	 */
	public function get_settings() {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::default_settings() );
	}

	/**
	 * Returns the default settings.
	 *
	 * @return array<string, string> Default settings.
	 */
	public static function default_settings() {
		return array(
			'greeting' => 'Hello',
		);
	}

	/**
	 * Builds the greeting for a name.
	 *
	 * The greeting word comes from the settings, so this is the piece of logic
	 * the integration tests exercise directly.
	 *
	 * @param string $name Name to greet. Defaults to the site title.
	 * @return string Unescaped greeting.
	 */
	public function greeting( $name = '' ) {
		$name = trim( (string) $name );

		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}

		$settings = $this->get_settings();

		return sprintf(
			/* translators: 1: configured greeting word, 2: name being greeted. */
			_x( '%1$s, %2$s!', 'greeting', 'hello-cursor' ),
			$settings['greeting'],
			$name
		);
	}

	/**
	 * Stores the default settings when the plugin is activated.
	 *
	 * @return void
	 */
	public static function activate() {
		add_option( self::OPTION_NAME, self::default_settings() );
		flush_rewrite_rules();
	}

	/**
	 * Cleans up rewrite rules when the plugin is deactivated.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
