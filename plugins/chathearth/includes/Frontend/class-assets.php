<?php
/**
 * Front-end assets and widget bootstrap.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Options;
use ChatHearth\Rest\Chat_Controller;
use ChatHearth\Security\Recaptcha;

/**
 * Enqueues the floating chatbot on public pages.
 */
final class Assets {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		// Print mount point before footer scripts (default priority 20).
		add_action( 'wp_footer', array( $this, 'render_root' ), 5 );
	}

	/**
	 * Enqueue CSS/JS when chat is enabled.
	 */
	public function enqueue(): void {
		if ( is_admin() || ! Options::is_chat_enabled() ) {
			return;
		}

		wp_enqueue_style(
			'chathearth-frontend',
			CHATHEARTH_URL . 'assets/css/frontend.css',
			array(),
			CHATHEARTH_VERSION
		);

		$recaptcha_enabled = Options::is_recaptcha_enabled();
		$recaptcha_passed  = $recaptcha_enabled && ( new Recaptcha() )->visitor_has_pass();
		$script_deps       = array();

		if ( $recaptcha_enabled ) {
			wp_enqueue_script(
				'google-recaptcha',
				'https://www.google.com/recaptcha/api.js?render=explicit',
				array(),
				'2',
				true
			);
			$script_deps[] = 'google-recaptcha';
		}

		wp_enqueue_script(
			'chathearth-frontend',
			CHATHEARTH_URL . 'assets/js/frontend.js',
			$script_deps,
			CHATHEARTH_VERSION,
			true
		);

		$settings = Options::all();

		wp_localize_script(
			'chathearth-frontend',
			'chatHearth',
			array(
				'restUrl'         => esc_url_raw( rest_url( Chat_Controller::REST_NAMESPACE . '/chat' ) ),
				'nonce'           => wp_create_nonce( Chat_Controller::NONCE_ACTION ),
				'welcome'         => (string) $settings['welcome_message'],
				'starters'        => Options::starter_phrases_list(),
				'headerTitle'     => (string) $settings['header_title'],
				'storageKey'      => 'chathearth_messages_' . (string) get_current_blog_id(),
				'recaptchaEnabled'=> $recaptcha_enabled,
				'recaptchaSiteKey'=> $recaptcha_enabled ? (string) $settings['recaptcha_site_key'] : '',
				'recaptchaPassed' => $recaptcha_passed,
				'i18n'            => array(
					'placeholder'      => __( 'Type your message…', 'chathearth' ),
					'send'             => __( 'Send', 'chathearth' ),
					'clear'            => __( 'Clear chat', 'chathearth' ),
					'close'            => __( 'Close chat', 'chathearth' ),
					'open'             => __( 'Open chat', 'chathearth' ),
					'error'            => __( 'Sorry, something went wrong. Please try again.', 'chathearth' ),
					'thinking'         => __( 'Thinking…', 'chathearth' ),
					'recaptchaRequired'=> __( 'Please complete the CAPTCHA before sending.', 'chathearth' ),
				),
				'styles'          => array(
					'iconShape'             => (string) $settings['icon_shape'],
					'iconBorderColor'       => (string) $settings['icon_border_color'],
					'iconBackgroundColor'   => (string) $settings['icon_background_color'],
					'iconColor'             => (string) $settings['icon_color'],
					'iconSize'              => (int) $settings['icon_size'],
					'position'              => (string) $settings['position'],
					'popupSize'             => (string) $settings['popup_size'],
					'userBubbleColor'       => (string) $settings['user_bubble_color'],
					'assistantBubbleColor'  => (string) $settings['assistant_bubble_color'],
					'launcherUrl'           => CHATHEARTH_URL . 'assets/images/launcher-default.svg',
				),
			)
		);
	}

	/**
	 * Mount point in the footer.
	 */
	public function render_root(): void {
		if ( is_admin() || ! Options::is_chat_enabled() ) {
			return;
		}

		echo '<div id="chathearth-root" class="chathearth-root" aria-live="polite"></div>';
	}
}
