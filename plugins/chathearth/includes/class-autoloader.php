<?php
/**
 * Simple PSR-4 style autoloader for ChatHearth.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader.
 */
final class Autoloader {

	/**
	 * Register the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Load a class file.
	 *
	 * @param string $fqcn Fully qualified class name.
	 */
	public static function load( string $fqcn ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $fqcn, $prefix ) ) {
			return;
		}

		$relative   = substr( $fqcn, strlen( $prefix ) );
		$parts      = explode( '\\', $relative );
		$class_name = array_pop( $parts );

		// Convert Class_Name to class-class-name.php (WordPress style).
		$file = 'class-' . str_replace( '_', '-', strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $class_name ) ) ) . '.php';

		$dir = CHATHEARTH_PATH . 'includes/';
		if ( ! empty( $parts ) ) {
			$dir .= implode( '/', $parts ) . '/';
		}

		$path = $dir . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
