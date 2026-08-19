<?php
/**
 * Per-IP and global rate limiter (object cache incr, locked fallback).
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Options;
use WP_Error;

/**
 * Limits chat requests by client IP and site-wide quotas.
 */
final class Rate_Limiter {

	private const CACHE_GROUP = 'chathearth';

	private const GLOBAL_MINUTE_KEY   = 'chathearth_rl_g_m';
	private const GLOBAL_HOUR_KEY     = 'chathearth_rl_g_h';
	private const GLOBAL_INCIDENT_KEY = 'chathearth_rl_g_inc';
	private const EMAIL_COOLDOWN_KEY  = 'chathearth_rl_email_cd';

	public const ABUSE_ALERT_OPTION = 'chathearth_abuse_alert';

	/** Max attempts to acquire the lock. */
	private const LOCK_ATTEMPTS = 3;

	/** Microseconds to wait between lock attempts. */
	private const LOCK_WAIT_US = 50000;

	/** Lock option TTL in seconds (stored as an expiry timestamp). */
	private const LOCK_TTL = 5;

	/** Min seconds between escalation emails. */
	private const EMAIL_COOLDOWN = 1800;

	/**
	 * Check whether the current request is allowed; increments counters if allowed.
	 *
	 * Order: global site limits, then per-IP limits.
	 *
	 * @return true|WP_Error
	 */
	public function allow_or_error() {
		$global_min  = (int) Options::get( 'global_rate_limit_per_minute', 60 );
		$global_hour = (int) Options::get( 'global_rate_limit_per_hour', 500 );

		$global = $this->consume_quota(
			self::GLOBAL_MINUTE_KEY,
			self::GLOBAL_HOUR_KEY,
			'global',
			$global_min,
			$global_hour
		);

		if ( is_wp_error( $global ) ) {
			if ( 'chathearth_rate_busy' !== $global->get_error_code() ) {
				$this->on_global_limit_hit();
				return $this->global_error();
			}
			return $global;
		}

		$ip   = $this->client_ip();
		$hash = md5( $ip );

		$per_minute = (int) Options::get( 'rate_limit_per_minute', 10 );
		$per_hour   = (int) Options::get( 'rate_limit_per_hour', 60 );

		return $this->consume_quota(
			'chathearth_rl_m_' . $hash,
			'chathearth_rl_h_' . $hash,
			$hash,
			$per_minute,
			$per_hour
		);
	}

	/**
	 * Consume one request against a minute/hour quota pair.
	 *
	 * @param string $minute_key Minute counter key.
	 * @param string $hour_key   Hour counter key.
	 * @param string $lock_id    Lock suffix (IP hash or "global").
	 * @param int    $per_minute Limit per minute.
	 * @param int    $per_hour   Limit per hour.
	 * @return true|WP_Error
	 */
	private function consume_quota( string $minute_key, string $hour_key, string $lock_id, int $per_minute, int $per_hour ) {
		if ( wp_using_ext_object_cache() ) {
			return $this->allow_via_object_cache( $minute_key, $hour_key, $per_minute, $per_hour );
		}

		return $this->allow_via_locked_transients( $lock_id, $minute_key, $hour_key, $per_minute, $per_hour );
	}

	/**
	 * Atomic counters via external object cache (Redis / Memcached).
	 *
	 * @param string $minute_key Minute counter key.
	 * @param string $hour_key   Hour counter key.
	 * @param int    $per_minute Limit per minute.
	 * @param int    $per_hour   Limit per hour.
	 * @return true|WP_Error
	 */
	private function allow_via_object_cache( string $minute_key, string $hour_key, int $per_minute, int $per_hour ) {
		wp_cache_add( $minute_key, 0, self::CACHE_GROUP, MINUTE_IN_SECONDS );
		$minute_count = wp_cache_incr( $minute_key, 1, self::CACHE_GROUP );

		if ( false === $minute_count ) {
			return $this->busy_error();
		}

		if ( (int) $minute_count > $per_minute ) {
			return $this->minute_error();
		}

		wp_cache_add( $hour_key, 0, self::CACHE_GROUP, HOUR_IN_SECONDS );
		$hour_count = wp_cache_incr( $hour_key, 1, self::CACHE_GROUP );

		if ( false === $hour_count ) {
			return $this->busy_error();
		}

		if ( (int) $hour_count > $per_hour ) {
			return $this->hour_error();
		}

		return true;
	}

	/**
	 * Read/modify/write under a short-lived exclusive option lock.
	 *
	 * @param string $lock_id    Lock suffix.
	 * @param string $minute_key Minute counter key.
	 * @param string $hour_key   Hour counter key.
	 * @param int    $per_minute Limit per minute.
	 * @param int    $per_hour   Limit per hour.
	 * @return true|WP_Error
	 */
	private function allow_via_locked_transients( string $lock_id, string $minute_key, string $hour_key, int $per_minute, int $per_hour ) {
		$lock_key = 'chathearth_rl_lock_' . $lock_id;

		if ( ! $this->acquire_lock( $lock_key ) ) {
			return $this->busy_error();
		}

		try {
			$minute_count = (int) get_transient( $minute_key );
			$hour_count   = (int) get_transient( $hour_key );

			if ( $minute_count >= $per_minute ) {
				return $this->minute_error();
			}

			if ( $hour_count >= $per_hour ) {
				return $this->hour_error();
			}

			set_transient( $minute_key, $minute_count + 1, MINUTE_IN_SECONDS );
			set_transient( $hour_key, $hour_count + 1, HOUR_IN_SECONDS );

			return true;
		} finally {
			delete_option( $lock_key );
		}
	}

	/**
	 * Record a global-limit denial and escalate when the incident threshold is reached.
	 */
	private function on_global_limit_hit(): void {
		$threshold = (int) Options::get( 'global_limit_incident_threshold', 3 );
		$incidents = $this->bump_incident_count();

		if ( $incidents < $threshold ) {
			return;
		}

		$this->escalate_to_admin( $incidents );
	}

	/**
	 * Increment the hourly global-limit incident counter.
	 */
	private function bump_incident_count(): int {
		$key = self::GLOBAL_INCIDENT_KEY;

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, self::CACHE_GROUP, HOUR_IN_SECONDS );
			$count = wp_cache_incr( $key, 1, self::CACHE_GROUP );
			return false === $count ? 1 : (int) $count;
		}

		$lock_key = 'chathearth_rl_lock_inc';
		if ( ! $this->acquire_lock( $lock_key ) ) {
			$count = (int) get_transient( $key );
			set_transient( $key, $count + 1, HOUR_IN_SECONDS );
			return $count + 1;
		}

		try {
			$count = (int) get_transient( $key );
			$next  = $count + 1;
			set_transient( $key, $next, HOUR_IN_SECONDS );
			return $next;
		} finally {
			delete_option( $lock_key );
		}
	}

	/**
	 * Email admin, store notice, optionally disable chat (with email cooldown).
	 *
	 * @param int $incidents Incident count in the current window.
	 */
	private function escalate_to_admin( int $incidents ): void {
		$auto_disable = (bool) Options::get( 'auto_disable_on_global_escalation', true );
		$disabled_now = false;

		if ( $auto_disable && Options::is_chat_enabled() ) {
			$settings                 = Options::all();
			$settings['chat_enabled'] = false;
			update_option( Options::OPTION_KEY, $settings );
			$disabled_now = true;
		}

		$alert = array(
			'time'          => time(),
			'incidents'     => $incidents,
			'auto_disabled' => $disabled_now,
		);
		update_option( self::ABUSE_ALERT_OPTION, $alert, false );

		if ( get_transient( self::EMAIL_COOLDOWN_KEY ) ) {
			return;
		}

		set_transient( self::EMAIL_COOLDOWN_KEY, 1, self::EMAIL_COOLDOWN );

		$admin_email = get_option( 'admin_email' );
		if ( ! is_string( $admin_email ) || '' === $admin_email ) {
			return;
		}

		$site_name    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$settings_url = admin_url( 'options-general.php?page=chathearth&tab=protection' );

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] ChatHearth - AI Chatbot: global rate limit escalation', 'chathearth' ),
			$site_name
		);

		$lines = array(
			__( 'ChatHearth - AI Chatbot blocked site-wide chat traffic after repeated global rate-limit hits.', 'chathearth' ),
			'',
			sprintf(
				/* translators: %d: number of global limit hits in the current hour */
				__( 'Global limit hits in the last hour: %d', 'chathearth' ),
				$incidents
			),
			sprintf(
				/* translators: 1: per-minute limit, 2: per-hour limit */
				__( 'Configured global limits: %1$d/minute, %2$d/hour', 'chathearth' ),
				(int) Options::get( 'global_rate_limit_per_minute', 60 ),
				(int) Options::get( 'global_rate_limit_per_hour', 500 )
			),
		);

		if ( $disabled_now ) {
			$lines[] = '';
			$lines[] = __( 'The chatbot has been automatically disabled. Re-enable it under Settings → ChatHearth - AI Chatbot → Protection when ready.', 'chathearth' );
		}

		$lines[] = '';
		$lines[] = __( 'Protection settings:', 'chathearth' );
		$lines[] = $settings_url;

		wp_mail( $admin_email, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Acquire an exclusive lock via add_option (succeeds only if the option is missing).
	 *
	 * @param string $lock_key Lock option name.
	 */
	private function acquire_lock( string $lock_key ): bool {
		for ( $i = 0; $i < self::LOCK_ATTEMPTS; $i++ ) {
			$now = time();

			$existing = get_option( $lock_key, false );
			if ( false !== $existing && is_numeric( $existing ) && (int) $existing < $now ) {
				delete_option( $lock_key );
			}

			if ( add_option( $lock_key, $now + self::LOCK_TTL, '', false ) ) {
				return true;
			}

			usleep( self::LOCK_WAIT_US );
		}

		return false;
	}

	/**
	 * Return the site-wide rate-limit error.
	 *
	 * @return WP_Error
	 */
	private function global_error(): WP_Error {
		return new WP_Error(
			'chathearth_global_rate_limited',
			__( 'The chatbot is temporarily unavailable due to high demand. Please try again later.', 'chathearth' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Return the per-minute rate-limit error.
	 *
	 * @return WP_Error
	 */
	private function minute_error(): WP_Error {
		return new WP_Error(
			'chathearth_rate_limited',
			__( 'Too many requests. Please wait a minute and try again.', 'chathearth' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Return the per-hour rate-limit error.
	 *
	 * @return WP_Error
	 */
	private function hour_error(): WP_Error {
		return new WP_Error(
			'chathearth_rate_limited',
			__( 'Hourly message limit reached. Please try again later.', 'chathearth' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Return the lock-contention error.
	 *
	 * @return WP_Error
	 */
	private function busy_error(): WP_Error {
		return new WP_Error(
			'chathearth_rate_busy',
			__( 'Too many requests. Please wait a moment and try again.', 'chathearth' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Best-effort client IP.
	 */
	private function client_ip(): string {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = '0.0.0.0';
		}

		/**
		 * Filter the IP address used for chatbot rate limiting.
		 *
		 * @param string $ip Detected IP.
		 */
		return (string) apply_filters( 'chathearth_client_ip', $ip );
	}
}
