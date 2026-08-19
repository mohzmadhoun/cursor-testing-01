<?php
/**
 * Plugin options helper.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and sanitizes plugin settings.
 */
final class Options {

	public const OPTION_KEY = 'chathearth_settings';

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'chat_enabled'            => true,
			'icon_shape'              => 'circle',
			'icon_border_color'       => '#1e293b',
			'icon_background_color'   => '#0f172a',
			'icon_color'              => '#ffffff',
			'icon_size'               => 56,
			'position'                => 'bottom-right',
			'popup_size'              => 'medium',
			'header_title'            => 'Chat with us',
			'user_bubble_color'       => '#0f172a',
			'assistant_bubble_color'  => '#e2e8f0',
			'welcome_message'         => 'Hi! How can I help you today?',
			'starter_phrases'         => "What do you offer?\nHow can I contact you?\nTell me about your services",
			'ai_provider'             => 'openai',
			'ai_model'                => defined( 'CHATHEARTH_DEFAULT_MODEL' ) ? CHATHEARTH_DEFAULT_MODEL : 'gpt-4.1',
			'system_prompt'           => 'You are a helpful assistant for this website. Be concise, friendly, and accurate. If you do not know something, say so.',
			'rate_limit_per_minute'            => 10,
			'rate_limit_per_hour'              => 60,
			'global_rate_limit_per_minute'     => 60,
			'global_rate_limit_per_hour'       => 500,
			'global_limit_incident_threshold'   => 3,
			'auto_disable_on_global_escalation' => true,
			'recaptcha_site_key'                => '',
			'recaptcha_secret_key'              => '',
			'max_message_length'               => 2000,
			'max_history_messages'             => 20,
			'content_moderation_enabled'       => true,
			'moderation_use_openai'            => true,
			'moderation_disallowed_phrases'    => '',
			'moderation_block_message'         => 'Sorry, that message cannot be processed. Please rephrase and try again.',
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Optional fallback.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $default;
	}

	/**
	 * Whether the chatbot is enabled.
	 */
	public static function is_chat_enabled(): bool {
		return (bool) self::get( 'chat_enabled', true );
	}

	/**
	 * Whether Google reCAPTCHA v2 is active (both site and secret keys configured).
	 */
	public static function is_recaptcha_enabled(): bool {
		$site   = trim( (string) self::get( 'recaptcha_site_key', '' ) );
		$secret = trim( (string) self::get( 'recaptcha_secret_key', '' ) );

		return '' !== $site && '' !== $secret;
	}

	/**
	 * Whether content moderation (keywords and/or OpenAI Moderations) is enabled.
	 */
	public static function is_content_moderation_enabled(): bool {
		return (bool) self::get( 'content_moderation_enabled', true );
	}

	/**
	 * Whether the OpenAI Moderations API layer is enabled (requires master toggle too).
	 */
	public static function is_moderation_openai_enabled(): bool {
		return (bool) self::get( 'moderation_use_openai', true );
	}

	/**
	 * Disallowed phrases as a list of strings (one per line in settings).
	 *
	 * @return list<string>
	 */
	public static function moderation_disallowed_phrases_list(): array {
		$raw   = (string) self::get( 'moderation_disallowed_phrases', '' );
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		if ( ! is_array( $lines ) ) {
			return array();
		}

		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * Supported AI providers for settings (v1: OpenAI only).
	 *
	 * @return array<string, string> Provider id => label.
	 */
	public static function available_providers(): array {
		return array(
			'openai' => 'OpenAI',
		);
	}

	/**
	 * OpenAI chat models offered in settings.
	 *
	 * @return array<string, string> Model id => label.
	 */
	public static function available_openai_models(): array {
		return array(
			'gpt-4.1'      => 'GPT-4.1',
			'gpt-4.1-mini' => 'GPT-4.1 Mini',
			'gpt-4.1-nano' => 'GPT-4.1 Nano',
			'gpt-4o'       => 'GPT-4o',
			'gpt-4o-mini'  => 'GPT-4o Mini',
			'o4-mini'      => 'o4-mini',
			'o3-mini'      => 'o3-mini',
		);
	}

	/**
	 * Starter phrases as a list of strings.
	 *
	 * @return list<string>
	 */
	public static function starter_phrases_list(): array {
		$raw = (string) self::get( 'starter_phrases', '' );
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		if ( ! is_array( $lines ) ) {
			return array();
		}

		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * Sanitize settings array from the admin form.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$out      = self::all();

		$out['chat_enabled'] = ! empty( $input['chat_enabled'] );

		$shape = isset( $input['icon_shape'] ) ? sanitize_key( (string) $input['icon_shape'] ) : $defaults['icon_shape'];
		$out['icon_shape'] = in_array( $shape, array( 'circle', 'square' ), true ) ? $shape : $defaults['icon_shape'];

		foreach ( array( 'icon_border_color', 'icon_background_color', 'icon_color', 'user_bubble_color', 'assistant_bubble_color' ) as $color_key ) {
			if ( isset( $input[ $color_key ] ) ) {
				$color = sanitize_hex_color( (string) $input[ $color_key ] );
				$out[ $color_key ] = $color ? $color : $defaults[ $color_key ];
			}
		}

		$size = isset( $input['icon_size'] ) ? absint( $input['icon_size'] ) : (int) $defaults['icon_size'];
		$out['icon_size'] = max( 40, min( 96, $size ) );

		$position = isset( $input['position'] ) ? sanitize_key( (string) $input['position'] ) : $defaults['position'];
		$out['position'] = in_array( $position, array( 'bottom-right', 'bottom-left' ), true ) ? $position : $defaults['position'];

		$popup = isset( $input['popup_size'] ) ? sanitize_key( (string) $input['popup_size'] ) : $defaults['popup_size'];
		$out['popup_size'] = in_array( $popup, array( 'small', 'medium', 'large' ), true ) ? $popup : $defaults['popup_size'];

		if ( isset( $input['header_title'] ) ) {
			$out['header_title'] = sanitize_text_field( (string) $input['header_title'] );
		}

		if ( isset( $input['welcome_message'] ) ) {
			$out['welcome_message'] = sanitize_textarea_field( (string) $input['welcome_message'] );
		}

		if ( isset( $input['starter_phrases'] ) ) {
			$out['starter_phrases'] = sanitize_textarea_field( (string) $input['starter_phrases'] );
		}

		if ( isset( $input['system_prompt'] ) ) {
			$out['system_prompt'] = sanitize_textarea_field( (string) $input['system_prompt'] );
		}

		$provider = isset( $input['ai_provider'] ) ? sanitize_key( (string) $input['ai_provider'] ) : $defaults['ai_provider'];
		$out['ai_provider'] = array_key_exists( $provider, self::available_providers() ) ? $provider : $defaults['ai_provider'];

		$model = isset( $input['ai_model'] ) ? sanitize_text_field( (string) $input['ai_model'] ) : $defaults['ai_model'];
		$out['ai_model'] = array_key_exists( $model, self::available_openai_models() ) ? $model : $defaults['ai_model'];

		$per_min = isset( $input['rate_limit_per_minute'] ) ? absint( $input['rate_limit_per_minute'] ) : (int) $defaults['rate_limit_per_minute'];
		$out['rate_limit_per_minute'] = max( 1, min( 120, $per_min ) );

		$per_hour = isset( $input['rate_limit_per_hour'] ) ? absint( $input['rate_limit_per_hour'] ) : (int) $defaults['rate_limit_per_hour'];
		$out['rate_limit_per_hour'] = max( 1, min( 1000, $per_hour ) );

		$g_min = isset( $input['global_rate_limit_per_minute'] ) ? absint( $input['global_rate_limit_per_minute'] ) : (int) $defaults['global_rate_limit_per_minute'];
		$out['global_rate_limit_per_minute'] = max( 1, min( 1000, $g_min ) );

		$g_hour = isset( $input['global_rate_limit_per_hour'] ) ? absint( $input['global_rate_limit_per_hour'] ) : (int) $defaults['global_rate_limit_per_hour'];
		$out['global_rate_limit_per_hour'] = max( 1, min( 10000, $g_hour ) );

		$threshold = isset( $input['global_limit_incident_threshold'] ) ? absint( $input['global_limit_incident_threshold'] ) : (int) $defaults['global_limit_incident_threshold'];
		$out['global_limit_incident_threshold'] = max( 1, min( 50, $threshold ) );

		$out['auto_disable_on_global_escalation'] = ! empty( $input['auto_disable_on_global_escalation'] );

		if ( isset( $input['recaptcha_site_key'] ) ) {
			$out['recaptcha_site_key'] = sanitize_text_field( (string) $input['recaptcha_site_key'] );
		}

		if ( ! empty( $input['recaptcha_clear_secret'] ) ) {
			$out['recaptcha_secret_key'] = '';
		} elseif ( isset( $input['recaptcha_secret_key'] ) ) {
			$secret = trim( (string) $input['recaptcha_secret_key'] );
			// Leave blank to keep the existing secret.
			if ( '' !== $secret ) {
				$out['recaptcha_secret_key'] = sanitize_text_field( $secret );
			}
		}

		$max_len = isset( $input['max_message_length'] ) ? absint( $input['max_message_length'] ) : (int) $defaults['max_message_length'];
		$out['max_message_length'] = max( 100, min( 8000, $max_len ) );

		$max_hist = isset( $input['max_history_messages'] ) ? absint( $input['max_history_messages'] ) : (int) $defaults['max_history_messages'];
		$out['max_history_messages'] = max( 2, min( 50, $max_hist ) );

		$out['content_moderation_enabled'] = ! empty( $input['content_moderation_enabled'] );
		$out['moderation_use_openai']      = ! empty( $input['moderation_use_openai'] );

		if ( isset( $input['moderation_disallowed_phrases'] ) ) {
			$out['moderation_disallowed_phrases'] = sanitize_textarea_field( (string) $input['moderation_disallowed_phrases'] );
		}

		if ( isset( $input['moderation_block_message'] ) ) {
			$msg = sanitize_text_field( (string) $input['moderation_block_message'] );
			$out['moderation_block_message'] = '' !== $msg ? $msg : (string) $defaults['moderation_block_message'];
		}

		return $out;
	}
}
