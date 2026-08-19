<?php
/**
 * Privacy Policy suggested content.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers suggested text for Settings → Privacy Policy.
 */
final class Privacy {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	/**
	 * Suggest privacy policy text describing chatbot data flows.
	 */
	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__(
			'ChatHearth - AI Chatbot lets visitors send messages to an AI assistant on this site. When someone uses the chatbot, the following may apply:',
			'chathearth'
		) . '</p>';

		$content .= '<ul>';
		$content .= '<li>' . esc_html__(
			'Message text and recent conversation history from the chat session are sent to a third-party AI provider (OpenAI in the current version) through WordPress Connectors / the site’s configured AI provider, so a reply can be generated.',
			'chathearth'
		) . '</li>';
		$content .= '<li>' . esc_html__(
			'Conversation history for the widget is stored in the visitor’s browser (local storage). The plugin does not store chat transcripts in the WordPress database in the current version.',
			'chathearth'
		) . '</li>';
		$content .= '<li>' . esc_html__(
			'The visitor’s IP address may be used temporarily for rate limiting and abuse protection. Related counters are short-lived and are not kept as a chat log.',
			'chathearth'
		) . '</li>';
		$content .= '<li>' . esc_html__(
			'If content moderation is enabled, message text (and prior user turns from the chat session) may also be sent to OpenAI’s Moderations API before a chat reply is generated, so harmful or disallowed content can be blocked.',
			'chathearth'
		) . '</li>';
		$content .= '<li>' . esc_html__(
			'If Google reCAPTCHA is enabled in the chatbot settings, Google may process a CAPTCHA challenge (and related technical data such as IP address) according to Google’s privacy policy.',
			'chathearth'
		) . '</li>';
		$content .= '<li>' . esc_html__(
			'If knowledge retrieval (RAG) is enabled, visitor questions are embedded and compared to indexed website content. Generated markdown copies of selected pages, posts, products, and taxonomies are stored on this site. Embeddings may be stored in the WordPress database, sent to a self-hosted Chroma server, or sent to Pinecone, depending on the store configured by the site owner. The OpenAI Embeddings API may be used (via the Connectors key) to create those vectors.',
			'chathearth'
		) . '</li>';
		$content .= '<li>' . esc_html__(
			'If a visitor adds a product to the cart from the chat widget, that action uses the WooCommerce cart on this site. Payment still happens on the store checkout.',
			'chathearth'
		) . '</li>';
		$content .= '<li>' . esc_html__(
			'This plugin does not store the AI provider API key; that credential is managed through WordPress Connectors or the server environment. A Pinecone API key, if configured, is stored in plugin settings.',
			'chathearth'
		) . '</li>';
		$content .= '</ul>';

		$content .= '<p>' . esc_html__(
			'Site owners should review the privacy policy of their chosen AI provider (and Google, if reCAPTCHA is used) and ensure this section matches how the chatbot is configured on their site.',
			'chathearth'
		) . '</p>';

		wp_add_privacy_policy_content(
			__( 'ChatHearth - AI Chatbot', 'chathearth' ),
			wp_kses_post( $content )
		);
	}
}
