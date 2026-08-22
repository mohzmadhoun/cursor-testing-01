<?php
/**
 * Conservative HTML to Markdown conversion for KB documents.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts common WordPress HTML into readable markdown.
 */
final class Html_To_Markdown {

	/**
	 * Convert an HTML fragment to markdown-ish plain text.
	 *
	 * @param string $html Raw HTML.
	 */
	public function convert( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html ) ?? $html;
		$html = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $html ) ?? $html;

		$html = preg_replace( '#<h1[^>]*>(.*?)</h1>#is', "\n# $1\n\n", $html ) ?? $html;
		$html = preg_replace( '#<h2[^>]*>(.*?)</h2>#is', "\n## $1\n\n", $html ) ?? $html;
		$html = preg_replace( '#<h3[^>]*>(.*?)</h3>#is', "\n### $1\n\n", $html ) ?? $html;
		$html = preg_replace( '#<h[4-6][^>]*>(.*?)</h[4-6]>#is', "\n#### $1\n\n", $html ) ?? $html;

		$html = preg_replace( '#<li[^>]*>(.*?)</li>#is', "- $1\n", $html ) ?? $html;
		$html = preg_replace( '#</?(?:ul|ol)[^>]*>#i', "\n", $html ) ?? $html;

		$html = preg_replace_callback(
			'#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
			static function ( array $matches ): string {
				$text = trim( wp_strip_all_tags( $matches[2] ) );
				$href = trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
				if ( '' === $text ) {
					return $href;
				}
				return '[' . $text . '](' . $href . ')';
			},
			$html
		) ?? $html;

		$html = preg_replace( '#<(strong|b)[^>]*>(.*?)</\1>#is', '**$2**', $html ) ?? $html;
		$html = preg_replace( '#<(em|i)[^>]*>(.*?)</\1>#is', '*$2*', $html ) ?? $html;
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html ) ?? $html;
		$html = preg_replace( '#</p>#i', "\n\n", $html ) ?? $html;
		$html = preg_replace( '#<p[^>]*>#i', '', $html ) ?? $html;
		$html = preg_replace( '#</?(div|span|section|article|figure|figcaption)[^>]*>#i', '', $html ) ?? $html;

		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+\n/", "\n", $text ) ?? $text;
		$text = preg_replace( "/\n{3,}/", "\n\n", $text ) ?? $text;

		return trim( $text );
	}
}
