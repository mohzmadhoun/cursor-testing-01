<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite with ChatHearth active.
 *
 * @package ChatHearth
 */

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! getenv( 'WP_TESTS_DIR' ) ) {
		exit;
	}
}

$chathearth_repo_dir = dirname( __DIR__, 3 );

if ( file_exists( $chathearth_repo_dir . '/vendor/autoload.php' ) ) {
	require_once $chathearth_repo_dir . '/vendor/autoload.php';
}

$chathearth_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $chathearth_tests_dir ) {
	$chathearth_tests_dir = '/var/www/wp-tests-lib';
}

$chathearth_tests_dir = rtrim( $chathearth_tests_dir, '/\\' );

if ( ! file_exists( $chathearth_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		'Could not find the WordPress test suite in ' . $chathearth_tests_dir . PHP_EOL
	);
	fwrite( STDERR, 'Run .cursor/install.sh, or point WP_TESTS_DIR at an existing installation.' . PHP_EOL );
	exit( 1 );
}

require_once $chathearth_tests_dir . '/includes/functions.php';

/**
 * Loads ChatHearth before WordPress finishes booting.
 */
function chathearth_manually_load_plugin() {
	require dirname( __DIR__ ) . '/chathearth.php';
}

tests_add_filter( 'muplugins_loaded', 'chathearth_manually_load_plugin' );

require $chathearth_tests_dir . '/includes/bootstrap.php';
