<?php
/**
 * Admin notices (dependencies + abuse escalation).
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Plugin;
use ChatHearth\Security\Rate_Limiter;

/**
 * Shows admin notices when OpenAI / Connectors are not ready, or abuse escalation fired.
 */
final class Admin_Notices {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_chathearth_dismiss_abuse_alert', array( $this, 'dismiss_abuse_alert' ) );
	}

	/**
	 * Render notices.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->render_abuse_alert();
		$this->render_dependency_notice();
	}

	/**
	 * Notice when OpenAI / Connectors are missing.
	 */
	private function render_dependency_notice(): void {
		if ( Plugin::is_openai_ready() ) {
			return;
		}

		$connectors_url = admin_url( 'options-connectors.php' );

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__(
			'ChatHearth - AI Chatbot needs WordPress AI support, the “AI Provider for OpenAI” plugin, and an OpenAI API key under Connectors. The chat icon stays hidden on the site until OpenAI is ready.',
			'chathearth'
		);
		echo ' <a href="' . esc_url( $connectors_url ) . '">' . esc_html__( 'Open Connectors settings', 'chathearth' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Notice after global rate-limit escalation.
	 */
	private function render_abuse_alert(): void {
		$alert = get_option( Rate_Limiter::ABUSE_ALERT_OPTION, null );
		if ( ! is_array( $alert ) || empty( $alert['time'] ) ) {
			return;
		}

		$settings_url = admin_url( 'options-general.php?page=chathearth&tab=protection' );
		$dismiss_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=chathearth_dismiss_abuse_alert' ),
			'chathearth_dismiss_abuse_alert'
		);

		$incidents = isset( $alert['incidents'] ) ? (int) $alert['incidents'] : 0;

		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'ChatHearth - AI Chatbot: global rate limits were hit repeatedly (possible multi-IP abuse).',
			'chathearth'
		);
		if ( $incidents > 0 ) {
			echo ' ';
			echo esc_html(
				sprintf(
					/* translators: %d: number of hits */
					__( 'Recorded hits in the window: %d.', 'chathearth' ),
					$incidents
				)
			);
		}
		if ( ! empty( $alert['auto_disabled'] ) ) {
			echo ' ';
			echo esc_html__( 'The chatbot was automatically disabled.', 'chathearth' );
		}
		echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open Protection settings', 'chathearth' ) . '</a>';
		echo ' | <a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss', 'chathearth' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Dismiss the abuse alert notice.
	 */
	public function dismiss_abuse_alert(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'chathearth' ) );
		}

		check_admin_referer( 'chathearth_dismiss_abuse_alert' );
		delete_option( Rate_Limiter::ABUSE_ALERT_OPTION );

		$redirect = wp_get_referer();
		if ( ! is_string( $redirect ) || '' === $redirect ) {
			$redirect = admin_url( 'options-general.php?page=chathearth&tab=protection' );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
