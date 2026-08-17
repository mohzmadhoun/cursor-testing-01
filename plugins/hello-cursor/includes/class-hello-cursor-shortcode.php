<?php
/**
 * Shortcode handler.
 *
 * @package Hello_Cursor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the [hello_cursor] shortcode.
 */
class Hello_Cursor_Shortcode {

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	const TAG = 'hello_cursor';

	/**
	 * Plugin instance.
	 *
	 * @var Hello_Cursor_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Hello_Cursor_Plugin $plugin Plugin instance.
	 */
	public function __construct( Hello_Cursor_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registers the shortcode.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Renders the shortcode.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @return string Shortcode markup.
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'name' => '',
			),
			$atts,
			self::TAG
		);

		return sprintf(
			'<p class="hello-cursor-greeting">%s</p>',
			esc_html( $this->plugin->greeting( $atts['name'] ) )
		);
	}
}
