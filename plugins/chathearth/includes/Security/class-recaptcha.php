<?php
/**
 * Google reCAPTCHA v2 verification.
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
 * Verifies reCAPTCHA v2 tokens when keys are configured.
 *
 * After a successful checkbox verification, a short-lived cookie pass lets the
 * visitor send more messages without solving CAPTCHA again.
 */
final class Recaptcha {

	private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

	private const COOKIE_NAME = 'chathearth_human';

	/** How long a successful CAPTCHA unlocks further chat requests. */
	private const PASS_TTL = 3600;

	/**
	 * Verify a client token when reCAPTCHA is enabled; otherwise succeed immediately.
	 *
	 * @param string $token Client response token.
	 * @return true|WP_Error
	 */
	public function verify_or_error( string $token ) {
		if ( ! Options::is_recaptcha_enabled() ) {
			return true;
		}

		if ( $this->has_valid_pass() ) {
			$this->refresh_pass_cookie();
			return true;
		}

		$token = trim( $token );
		if ( '' === $token ) {
			return new WP_Error(
				'chathearth_recaptcha_missing',
				__( 'Please complete the CAPTCHA and try again.', 'chathearth' ),
				array( 'status' => 400 )
			);
		}

		$secret = trim( (string) Options::get( 'recaptcha_secret_key', '' ) );
		$body   = array(
			'secret'   => $secret,
			'response' => $token,
		);

		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$body['remoteip'] = $ip;
		}

		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => 10,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'chathearth_recaptcha_unavailable',
				__( 'CAPTCHA verification is temporarily unavailable. Please try again.', 'chathearth' ),
				array( 'status' => 503 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $data ) || empty( $data['success'] ) ) {
			return new WP_Error(
				'chathearth_recaptcha_failed',
				__( 'CAPTCHA verification failed. Please try again.', 'chathearth' ),
				array( 'status' => 403 )
			);
		}

		$this->issue_pass();

		return true;
	}

	/**
	 * Whether the current request already has a valid human-pass (for UI bootstrap).
	 */
	public function visitor_has_pass(): bool {
		return $this->has_valid_pass();
	}

	/**
	 * Whether the browser already holds a valid human-pass cookie.
	 */
	private function has_valid_pass(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared via hash against transient.
		$raw = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) : '';
		$raw = sanitize_text_field( $raw );
		if ( '' === $raw || ! preg_match( '/^[a-f0-9]{64}$/', $raw ) ) {
			return false;
		}

		return (bool) get_transient( $this->pass_transient_key( $raw ) );
	}

	/**
	 * Create a new human-pass cookie after a successful reCAPTCHA solve.
	 */
	private function issue_pass(): void {
		$token = bin2hex( random_bytes( 32 ) );
		set_transient( $this->pass_transient_key( $token ), 1, self::PASS_TTL );
		$this->set_pass_cookie( $token );
	}

	/**
	 * Extend cookie + transient for an active pass.
	 */
	private function refresh_pass_cookie(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( (string) wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';
		if ( '' === $raw || ! preg_match( '/^[a-f0-9]{64}$/', $raw ) ) {
			return;
		}

		set_transient( $this->pass_transient_key( $raw ), 1, self::PASS_TTL );
		$this->set_pass_cookie( $raw );
	}

	/**
	 * @param string $token Pass token.
	 */
	private function set_pass_cookie( string $token ): void {
		$secure   = is_ssl();
		$httponly = true;
		$path     = COOKIEPATH ? COOKIEPATH : '/';
		$domain   = COOKIE_DOMAIN ? COOKIE_DOMAIN : '';

		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie(
				self::COOKIE_NAME,
				$token,
				array(
					'expires'  => time() + self::PASS_TTL,
					'path'     => $path,
					'domain'   => $domain,
					'secure'   => $secure,
					'httponly' => $httponly,
					'samesite' => 'Lax',
				)
			);
		} else {
			setcookie( self::COOKIE_NAME, $token, time() + self::PASS_TTL, $path . '; samesite=Lax', $domain, $secure, $httponly );
		}

		$_COOKIE[ self::COOKIE_NAME ] = $token;
	}

	/**
	 * @param string $token Pass token.
	 */
	private function pass_transient_key( string $token ): string {
		return 'chathearth_human_' . md5( $token );
	}
}
