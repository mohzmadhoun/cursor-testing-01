<?php
/**
 * Uninstall cleanup.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'chathearth_settings' );
delete_option( 'chathearth_abuse_alert' );

if ( ! defined( 'CHATHEARTH_PATH' ) ) {
	define( 'CHATHEARTH_PATH', plugin_dir_path( __FILE__ ) );
}

require_once CHATHEARTH_PATH . 'includes/class-autoloader.php';
ChatHearth\Autoloader::register();

ChatHearth\Rag\Schema::uninstall();
