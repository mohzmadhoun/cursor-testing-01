<?php
/**
 * Settings screen.
 *
 * @package Hello_Cursor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds Settings -> Hello Cursor using the WordPress settings API.
 */
class Hello_Cursor_Settings {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'hello-cursor';

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
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
	}

	/**
	 * Registers the option and its fields.
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			self::PAGE_SLUG,
			Hello_Cursor_Plugin::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Hello_Cursor_Plugin::default_settings(),
			)
		);

		add_settings_section(
			'hello_cursor_main',
			__( 'Greeting', 'hello-cursor' ),
			array( $this, 'render_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'hello_cursor_greeting',
			__( 'Greeting word', 'hello-cursor' ),
			array( $this, 'render_greeting_field' ),
			self::PAGE_SLUG,
			'hello_cursor_main',
			array( 'label_for' => 'hello_cursor_greeting' )
		);
	}

	/**
	 * Adds the settings page under the Settings menu.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Hello Cursor', 'hello-cursor' ),
			__( 'Hello Cursor', 'hello-cursor' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Validates and normalises the submitted settings.
	 *
	 * @param mixed $input Raw value submitted by the settings form.
	 * @return array<string, string> Sanitised settings.
	 */
	public function sanitize( $input ) {
		$defaults = Hello_Cursor_Plugin::default_settings();

		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		$greeting = isset( $input['greeting'] ) ? sanitize_text_field( wp_unslash( (string) $input['greeting'] ) ) : '';

		return array(
			'greeting' => '' === $greeting ? $defaults['greeting'] : $greeting,
		);
	}

	/**
	 * Describes the settings section.
	 *
	 * @return void
	 */
	public function render_section() {
		echo '<p>' . esc_html__( 'The word used by the [hello_cursor] shortcode and the REST route.', 'hello-cursor' ) . '</p>';
	}

	/**
	 * Renders the greeting input.
	 *
	 * @return void
	 */
	public function render_greeting_field() {
		$settings = $this->plugin->get_settings();

		printf(
			'<input type="text" id="hello_cursor_greeting" name="%1$s[greeting]" value="%2$s" class="regular-text" />',
			esc_attr( Hello_Cursor_Plugin::OPTION_NAME ),
			esc_attr( $settings['greeting'] )
		);
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php echo esc_html( $this->plugin->greeting( __( 'from the settings page', 'hello-cursor' ) ) ); ?></p>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::PAGE_SLUG );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
