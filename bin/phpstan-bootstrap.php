<?php
/**
 * PHPStan bootstrap.
 *
 * Plugins normally define constants such as MY_PLUGIN_URL with
 * `define( 'MY_PLUGIN_URL', plugin_dir_url( __FILE__ ) )`. PHPStan only knows
 * about `define()`d constants whose value it can resolve statically, so any
 * constant built from a function call looks undefined when it is used from
 * another file. Declaring the names here keeps that false positive away without
 * having to touch the analysis rules.
 *
 * @package Workspace
 */

$constant_files = glob( dirname( __DIR__ ) . '/plugins/*/*.php' );

foreach ( $constant_files ?: array() as $file ) {
	$contents = file_get_contents( $file );

	if ( false === $contents ) {
		continue;
	}

	preg_match_all( '/\bdefine\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]\s*,/', $contents, $matches );

	foreach ( $matches[1] as $name ) {
		if ( ! defined( $name ) ) {
			define( $name, '' );
		}
	}
}
