<?php
/**
 * Conditional error logging into WordPress debug.log.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes to PHP / WordPress error log only when WP_DEBUG_LOG is already enabled.
 * Does not define or force WP_DEBUG / WP_DEBUG_LOG.
 */
final class Logger {

	/**
	 * Log an error-level message when WP_DEBUG_LOG is on.
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Optional scalar context (no secrets).
	 */
	public static function error( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		$line = '[chathearth] ' . self::sanitize_line( $message );

		if ( ! empty( $context ) ) {
			$parts = array();
			foreach ( $context as $key => $value ) {
				if ( ! is_scalar( $value ) && null !== $value ) {
					continue;
				}
				$parts[] = sanitize_key( (string) $key ) . '=' . self::sanitize_line( (string) $value );
			}
			if ( ! empty( $parts ) ) {
				$line .= ' ' . implode( ' ', $parts );
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional when WP_DEBUG_LOG is on.
		error_log( $line );
	}

	/**
	 * Collapse newlines so log lines stay single-line.
	 *
	 * @param string $text Raw text.
	 */
	private static function sanitize_line( string $text ): string {
		return trim( preg_replace( '/[\r\n]+/', ' ', $text ) ?? $text );
	}
}
