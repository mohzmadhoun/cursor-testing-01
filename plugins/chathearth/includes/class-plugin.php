<?php
/**
 * Main plugin bootstrap.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Admin\Admin_Notices;
use ChatHearth\Admin\Privacy;
use ChatHearth\Admin\Settings_Page;
use ChatHearth\Frontend\Assets;
use ChatHearth\Rest\Chat_Controller;

/**
 * Plugin singleton.
 */
final class Plugin {

	/**
	 * Shared plugin instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Activation defaults.
	 */
	public static function activate(): void {
		if ( false === get_option( Options::OPTION_KEY ) ) {
			add_option( Options::OPTION_KEY, Options::defaults() );
		}
	}

	/**
	 * Wire hooks.
	 */
	public function init(): void {
		( new Admin_Notices() )->register();
		( new Privacy() )->register();
		( new Settings_Page() )->register();
		( new Assets() )->register();

		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_rest(): void {
		( new Chat_Controller() )->register_routes();
	}

	/**
	 * Whether AI Client + OpenAI provider look available.
	 */
	public static function is_openai_ready(): bool {
		if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			return false;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( 'ai-provider-for-openai/plugin.php' ) ) {
			return false;
		}

		if ( function_exists( 'wp_is_connector_registered' ) && ! wp_is_connector_registered( 'openai' ) ) {
			return false;
		}

		return self::is_provider_configured( 'openai' );
	}

	/**
	 * Whether an AI provider is configured with working credentials.
	 *
	 * Uses the WordPress AI Client registry so the plugin never reads provider
	 * API keys directly — those credentials were granted by the user to
	 * WordPress, not to this plugin. Result is not cached so removing a key
	 * (or adding one) is reflected immediately.
	 *
	 * @param string $provider Provider id (e.g. "openai").
	 */
	private static function is_provider_configured( string $provider ): bool {
		if ( ! class_exists( '\\WordPress\\AiClient\\AiClient' ) ) {
			return false;
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			return $registry->hasProvider( $provider ) && $registry->isProviderConfigured( $provider );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
