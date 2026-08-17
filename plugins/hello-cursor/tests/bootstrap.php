<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite with this plugin active.
 *
 * @package Hello_Cursor
 */

$hello_cursor_repo_dir = dirname( __DIR__, 3 );

if ( file_exists( $hello_cursor_repo_dir . '/vendor/autoload.php' ) ) {
	require_once $hello_cursor_repo_dir . '/vendor/autoload.php';
}

$hello_cursor_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $hello_cursor_tests_dir ) {
	$hello_cursor_tests_dir = '/var/www/wp-tests-lib';
}

$hello_cursor_tests_dir = rtrim( $hello_cursor_tests_dir, '/\\' );

if ( ! file_exists( $hello_cursor_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test suite in {$hello_cursor_tests_dir}." . PHP_EOL;
	echo 'Run .cursor/install.sh, or point WP_TESTS_DIR at an existing installation.' . PHP_EOL;
	exit( 1 );
}

require_once $hello_cursor_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin before WordPress finishes booting.
 */
function hello_cursor_manually_load_plugin() {
	require dirname( __DIR__ ) . '/hello-cursor.php';
}

tests_add_filter( 'muplugins_loaded', 'hello_cursor_manually_load_plugin' );

require $hello_cursor_tests_dir . '/includes/bootstrap.php';
