<?php
/**
 * Google reCAPTCHA v3 verification tests.
 *
 * @package ChatHearth
 */

use ChatHearth\Options;
use ChatHearth\Rest\Chat_Controller;
use ChatHearth\Security\Recaptcha;

/**
 * Covers v3 token checks without calling Google.
 */
class Test_ChatHearth_Recaptcha extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Options::OPTION_KEY );
		unset( $_COOKIE['chathearth_human'] );
		add_filter( 'chathearth_pre_env_secret', array( $this, 'ignore_env_secrets' ), 10, 2 );
		add_filter( 'chathearth_pre_recaptcha_verify', array( $this, 'fake_google' ), 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'chathearth_pre_env_secret', array( $this, 'ignore_env_secrets' ), 10 );
		remove_filter( 'chathearth_pre_recaptcha_verify', array( $this, 'fake_google' ), 10 );
		parent::tear_down();
	}

	/**
	 * Ignore Cloud Agent recaptcha environment secrets so these tests cover settings-only keys.
	 *
	 * @param mixed  $pre  Existing value.
	 * @param string $name Variable name.
	 */
	public function ignore_env_secrets( $pre, string $name ): string {
		unset( $pre, $name );

		return '';
	}

	/**
	 * Stub Google verification for tests.
	 *
	 * @param mixed  $pre   Existing value.
	 * @param string $token Token.
	 * @return true|\WP_Error|null
	 */
	public function fake_google( $pre, $token ) {
		unset( $pre );
		if ( 'ok-token' === $token ) {
			return true;
		}
		if ( 'bad-token' === $token ) {
			return new WP_Error(
				'chathearth_recaptcha_failed',
				'CAPTCHA verification failed. Please try again.',
				array( 'status' => 403 )
			);
		}

		return null;
	}

	public function test_recaptcha_disabled_without_keys() {
		$this->assertFalse( Options::is_recaptcha_enabled() );
		$this->assertTrue( ( new Recaptcha() )->verify_or_error( '' ) );
	}

	public function test_missing_token_fails_when_keys_are_set() {
		update_option(
			Options::OPTION_KEY,
			array(
				'recaptcha_site_key'   => 'site',
				'recaptcha_secret_key' => 'secret',
			)
		);

		$result = ( new Recaptcha() )->verify_or_error( '' );
		$this->assertWPError( $result );
		$this->assertSame( 'chathearth_recaptcha_missing', $result->get_error_code() );
	}

	public function test_valid_token_unlocks_and_later_requests_skip_token() {
		update_option(
			Options::OPTION_KEY,
			array(
				'recaptcha_site_key'   => 'site',
				'recaptcha_secret_key' => 'secret',
			)
		);

		$this->assertTrue( ( new Recaptcha() )->verify_or_error( 'ok-token' ) );
		$this->assertTrue( ( new Recaptcha() )->visitor_has_pass() );
		$this->assertTrue( ( new Recaptcha() )->verify_or_error( '' ) );
	}

	public function test_failed_token_is_rejected() {
		update_option(
			Options::OPTION_KEY,
			array(
				'recaptcha_site_key'   => 'site',
				'recaptcha_secret_key' => 'secret',
			)
		);

		$result = ( new Recaptcha() )->verify_or_error( 'bad-token' );
		$this->assertWPError( $result );
		$this->assertSame( 'chathearth_recaptcha_failed', $result->get_error_code() );
	}

	public function test_min_score_is_clamped() {
		$settings = Options::sanitize(
			array(
				'recaptcha_min_score' => 9,
			)
		);
		$this->assertSame( 1.0, $settings['recaptcha_min_score'] );

		$settings = Options::sanitize(
			array(
				'recaptcha_min_score' => -1,
			)
		);
		$this->assertSame( 0.0, $settings['recaptcha_min_score'] );
	}

	public function test_recaptcha_rest_route_is_registered() {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$this->assertArrayHasKey( '/chathearth/v1/recaptcha', $wp_rest_server->get_routes() );
	}

	public function test_recaptcha_rest_rejects_missing_nonce() {
		$controller = new Chat_Controller();
		$request    = new WP_REST_Request( 'POST', '/chathearth/v1/recaptcha' );
		$result     = $controller->permission_check( $request );
		$this->assertWPError( $result );
		$this->assertSame( 'chathearth_invalid_nonce', $result->get_error_code() );
	}
}
