<?php
/**
 * Plugin Name:       Hello Cursor
 * Description:       Reference plugin for this workspace: settings page, shortcode, REST route and integration tests.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Cursor
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hello-cursor
 * Domain Path:       /languages
 *
 * @package Hello_Cursor
 */

defined( 'ABSPATH' ) || exit;

define( 'HELLO_CURSOR_VERSION', '0.1.0' );
define( 'HELLO_CURSOR_FILE', __FILE__ );
define( 'HELLO_CURSOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'HELLO_CURSOR_URL', plugin_dir_url( __FILE__ ) );

require_once HELLO_CURSOR_PATH . 'includes/class-hello-cursor-plugin.php';
require_once HELLO_CURSOR_PATH . 'includes/class-hello-cursor-settings.php';
require_once HELLO_CURSOR_PATH . 'includes/class-hello-cursor-shortcode.php';
require_once HELLO_CURSOR_PATH . 'includes/class-hello-cursor-rest-controller.php';

/**
 * Returns the shared plugin instance.
 *
 * @return Hello_Cursor_Plugin
 */
function hello_cursor() {
	return Hello_Cursor_Plugin::instance();
}

hello_cursor()->register();

register_activation_hook( HELLO_CURSOR_FILE, array( 'Hello_Cursor_Plugin', 'activate' ) );
register_deactivation_hook( HELLO_CURSOR_FILE, array( 'Hello_Cursor_Plugin', 'deactivate' ) );
