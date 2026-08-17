<?php
/**
 * REST API controller.
 *
 * @package Hello_Cursor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the greeting at /wp-json/hello-cursor/v1/greeting.
 */
class Hello_Cursor_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'hello-cursor/v1';

	/**
	 * Route below the namespace.
	 *
	 * @var string
	 */
	const ROUTE = '/greeting';

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
	 * Registers the REST hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the greeting route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_greeting' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'name' => array(
						'description'       => __( 'Name to greet.', 'hello-cursor' ),
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Returns the greeting for the requested name.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response Greeting payload.
	 */
	public function get_greeting( WP_REST_Request $request ) {
		$name = (string) $request->get_param( 'name' );

		return rest_ensure_response(
			array(
				'greeting' => $this->plugin->greeting( $name ),
				'name'     => $name,
			)
		);
	}
}
