<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite with this plugin active.
 *
 * @package Mzm_Current_Year
 */

$mzm_current_year_repo_dir = dirname( __DIR__, 3 );

if ( file_exists( $mzm_current_year_repo_dir . '/vendor/autoload.php' ) ) {
	require_once $mzm_current_year_repo_dir . '/vendor/autoload.php';
}

$mzm_current_year_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $mzm_current_year_tests_dir ) {
	$mzm_current_year_tests_dir = '/var/www/wp-tests-lib';
}

$mzm_current_year_tests_dir = rtrim( $mzm_current_year_tests_dir, '/\\' );

if ( ! file_exists( $mzm_current_year_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test suite in {$mzm_current_year_tests_dir}." . PHP_EOL;
	exit( 1 );
}

require_once $mzm_current_year_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin before WordPress finishes booting.
 */
function mzm_current_year_manually_load_plugin() {
	require dirname( __DIR__ ) . '/mzm-current-year.php';
}

tests_add_filter( 'muplugins_loaded', 'mzm_current_year_manually_load_plugin' );

require $mzm_current_year_tests_dir . '/includes/bootstrap.php';
