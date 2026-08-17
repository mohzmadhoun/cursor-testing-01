<?php
/**
 * Removes the plugin data when it is deleted from the plugins screen.
 *
 * @package Hello_Cursor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'hello_cursor_settings' );
