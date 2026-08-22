<?php
/**
 * Plugin Name:       ChatHearth - AI Chatbot
 * Description:       Site-wide AI chatbot powered by WordPress Connectors (OpenAI in v1).
 * Version:           1.4.4
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Requires Plugins:  ai-provider-for-openai
 * Author:            PalWP
 * Author URI:        https://palwp.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chathearth
 * Domain Path:       /languages
 *
 * @package ChatHearth
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CHATHEARTH_VERSION', '1.4.4' );
define( 'CHATHEARTH_FILE', __FILE__ );
define( 'CHATHEARTH_PATH', plugin_dir_path( __FILE__ ) );
define( 'CHATHEARTH_URL', plugin_dir_url( __FILE__ ) );
define( 'CHATHEARTH_BASENAME', plugin_basename( __FILE__ ) );

/** Fixed default OpenAI model for v1 (not exposed in settings). */
define( 'CHATHEARTH_DEFAULT_MODEL', 'gpt-4.1' );

require_once CHATHEARTH_PATH . 'includes/class-autoloader.php';
ChatHearth\Autoloader::register();

register_activation_hook( __FILE__, array( 'ChatHearth\\Plugin', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		ChatHearth\Plugin::instance()->init();
	}
);
