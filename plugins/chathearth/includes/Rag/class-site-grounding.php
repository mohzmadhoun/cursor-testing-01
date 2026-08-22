<?php
/**
 * Always-on website grounding for the system prompt.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects site identity and off-topic refusal rules even when RAG is disabled.
 */
final class Site_Grounding {

	public const TRANSIENT_KEY = 'chathearth_site_grounding';

	/**
	 * Register the system-prompt filter.
	 */
	public function register(): void {
		add_filter( 'chathearth_system_prompt', array( $this, 'inject' ), 5, 3 );
		add_action( 'save_post_page', array( $this, 'bust_cache' ) );
		add_action( 'deleted_post', array( $this, 'bust_cache' ) );
	}

	/**
	 * Wrap the admin system prompt with site-scope rules.
	 *
	 * @param string $prompt  Admin system prompt.
	 * @param string $message Current user message.
	 * @param array  $history History.
	 */
	public function inject( string $prompt, string $message, array $history ): string {
		unset( $message, $history );

		$block = $this->rules() . "\n\n" . $this->site_block();
		$extra = trim( $prompt );
		if ( '' !== $extra ) {
			$block .= "\n\n## Additional instructions from the site owner\n" . $extra . "\n";
			$block .= "If those instructions conflict with the website-only scope above, the website-only scope wins.\n";
		}

		return $block;
	}

	/**
	 * Drop the cached site block.
	 */
	public function bust_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Hard rules.
	 */
	public function rules(): string {
		$name = html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );
		if ( '' === $name ) {
			$name = 'this website';
		}

		return <<<TXT
You are the on-site assistant for {$name} only.

Hard rules:
- Answer only questions about this website, its pages, posts, products, categories, policies, store, shipping, and related services described in the site context or retrieved knowledge.
- If the visitor asks about anything else (world news, homework, unrelated companies, general trivia, other stores not mentioned here), politely refuse in one or two sentences and offer help with this website instead.
- Never invent pages, URLs, prices, stock, or products. If the information is not in the site context or retrieved knowledge, say you do not have it.
- When you mention a page, post, product, or category, include a Markdown link to its URL.
- For product comparisons, use a Markdown table or a clear side-by-side list, using only facts from the catalog/knowledge. Do not invent attributes.
- If the visitor wants to buy something, tell them they can use Add to cart in this chat (when a matching product is shown) or open the cart/checkout links. Do not claim you completed a paid order.
TXT;
	}

	/**
	 * Compact site identity block (cached).
	 */
	public function site_block(): string {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$exporter = new Markdown_Exporter();
		$doc      = $exporter->export_site_identity();
		$body     = (string) $doc['markdown'];
		$woo      = $exporter->export_woocommerce();
		if ( is_array( $woo ) ) {
			$body .= "\n\n" . (string) $woo['markdown'];
		}

		$block = "## Website context\n" . $body;
		set_transient( self::TRANSIENT_KEY, $block, 10 * MINUTE_IN_SECONDS );

		return $block;
	}
}
