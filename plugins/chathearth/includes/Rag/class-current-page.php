<?php
/**
 * Current-page context for chat replies.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Commerce\Product_Catalog;

/**
 * Resolves the visitor's public WordPress page and injects it into the prompt.
 */
final class Current_Page {

	public const MAX_CHARS = 8000;

	/**
	 * Shared instance (REST capture + system-prompt filter).
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Requested object id (post or term).
	 *
	 * @var int
	 */
	private $page_id = 0;

	/**
	 * Requested object type: post, term, front, url.
	 *
	 * @var string
	 */
	private $page_type = '';

	/**
	 * Term taxonomy when type is term.
	 *
	 * @var string
	 */
	private $taxonomy = '';

	/**
	 * Visitor URL (must be this site).
	 *
	 * @var string
	 */
	private $page_url = '';

	/**
	 * Citation for the last resolved page.
	 *
	 * @var array<string, mixed>|null
	 */
	private $source = null;

	/**
	 * Product card when the current page is a public product.
	 *
	 * @var array<string, mixed>|null
	 */
	private $product = null;

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
	 * Register the system-prompt filter.
	 */
	public function register(): void {
		add_filter( 'chathearth_system_prompt', array( $this, 'inject' ), 10, 3 );
	}

	/**
	 * Front-end payload for the page the widget is rendered on.
	 *
	 * @return array{id:int,type:string,taxonomy:string,url:string,title:string}
	 */
	public static function frontend_payload(): array {
		$url = self::current_request_url();
		$obj = get_queried_object();

		if ( is_singular() && $obj instanceof \WP_Post ) {
			return array(
				'id'       => (int) $obj->ID,
				'type'     => 'post',
				'taxonomy' => '',
				'url'      => (string) get_permalink( $obj ),
				'title'    => self::decode( get_the_title( $obj ) ),
			);
		}

		if ( ( is_category() || is_tag() || is_tax() ) && $obj instanceof \WP_Term ) {
			$link = get_term_link( $obj );

			return array(
				'id'       => (int) $obj->term_id,
				'type'     => 'term',
				'taxonomy' => (string) $obj->taxonomy,
				'url'      => is_wp_error( $link ) ? $url : (string) $link,
				'title'    => self::decode( $obj->name ),
			);
		}

		$front = (int) get_option( 'page_on_front' );
		if ( is_front_page() && $front > 0 ) {
			return array(
				'id'       => $front,
				'type'     => 'post',
				'taxonomy' => '',
				'url'      => (string) get_permalink( $front ),
				'title'    => self::decode( get_the_title( $front ) ),
			);
		}

		if ( is_front_page() || is_home() ) {
			return array(
				'id'       => 0,
				'type'     => 'front',
				'taxonomy' => '',
				'url'      => home_url( '/' ),
				'title'    => self::decode( (string) get_bloginfo( 'name' ) ),
			);
		}

		return array(
			'id'       => 0,
			'type'     => 'url',
			'taxonomy' => '',
			'url'      => $url,
			'title'    => self::decode( wp_get_document_title() ),
		);
	}

	/**
	 * Store identifiers from the chat REST request.
	 *
	 * @param int    $page_id   Post or term id.
	 * @param string $page_type Object type.
	 * @param string $taxonomy  Taxonomy slug.
	 * @param string $page_url  Page URL.
	 */
	public function capture( int $page_id, string $page_type, string $taxonomy, string $page_url ): void {
		$this->page_id   = max( 0, $page_id );
		$this->page_type = sanitize_key( $page_type );
		$this->taxonomy  = sanitize_key( $taxonomy );
		$this->page_url  = $this->same_site_url( $page_url );
		$this->source    = null;
		$this->product   = null;
	}

	/**
	 * Clear request-scoped state (tests).
	 */
	public function reset(): void {
		$this->capture( 0, '', '', '' );
	}

	/**
	 * Citation for the resolved current page, if any.
	 *
	 * @return array<string, mixed>|null
	 */
	public function source(): ?array {
		return $this->source;
	}

	/**
	 * Product card when the current page is a purchasable catalog item.
	 *
	 * @return array<string, mixed>|null
	 */
	public function product(): ?array {
		return $this->product;
	}

	/**
	 * Append current-page markdown to the system prompt.
	 *
	 * @param string               $prompt  System prompt.
	 * @param string               $message User message.
	 * @param array<string, mixed> $history History.
	 */
	public function inject( string $prompt, string $message, array $history ): string {
		unset( $message, $history );

		$doc = $this->resolve();
		if ( is_array( $doc ) ) {
			$body  = $this->truncate( (string) $doc['markdown'] );
			$title = isset( $doc['title'] ) ? (string) $doc['title'] : '';
			$url   = isset( $doc['url'] ) ? (string) $doc['url'] : '';
			$type  = isset( $doc['post_type'] ) && '' !== (string) $doc['post_type']
				? (string) $doc['post_type']
				: (string) ( $doc['object_type'] ?? 'content' );

			$prompt .= "\n\n## Current page\n";
			$prompt .= "The visitor is looking at this page right now. If they ask about \"this page\", \"this product\", \"here\", or similar, answer from this content first. Still refuse unrelated off-site topics. Cite this page with a Markdown link.\n\n";
			if ( '' !== $title ) {
				$prompt .= 'Title: ' . $title . "\n";
			}
			if ( '' !== $url ) {
				$prompt .= 'URL: ' . $url . "\n";
			}
			$prompt .= 'Type: ' . $type . "\n\n";
			$prompt .= $body . "\n";

			return $prompt;
		}

		if ( '' !== $this->page_url ) {
			$prompt .= "\n\n## Current page\nThe visitor is on " . $this->page_url . ". No public WordPress post or term matched this URL. Use website context and retrieved knowledge. Do not invent page content.\n";
		}

		return $prompt;
	}

	/**
	 * Resolve public WordPress content for the captured identifiers.
	 *
	 * @return array<string, mixed>|null
	 */
	public function resolve(): ?array {
		if ( 'term' === $this->page_type && $this->page_id > 0 ) {
			$doc = $this->from_term( $this->page_id, $this->taxonomy );
			if ( is_array( $doc ) ) {
				return $this->remember( $doc );
			}
		} elseif ( $this->page_id > 0 ) {
			$doc = $this->from_post( $this->page_id );
			if ( is_array( $doc ) ) {
				return $this->remember( $doc );
			}
		}

		if ( '' !== $this->page_url ) {
			$post_id = url_to_postid( $this->page_url );
			if ( $post_id > 0 ) {
				$doc = $this->from_post( $post_id );
				if ( is_array( $doc ) ) {
					return $this->remember( $doc );
				}
			}

			$term_doc = $this->term_from_url( $this->page_url );
			if ( is_array( $term_doc ) ) {
				return $this->remember( $term_doc );
			}

			if ( $this->urls_match( $this->page_url, home_url( '/' ) ) ) {
				$front = (int) get_option( 'page_on_front' );
				if ( $front > 0 ) {
					$doc = $this->from_post( $front );
					if ( is_array( $doc ) ) {
						return $this->remember( $doc );
					}
				}
			}
		}

		return null;
	}

	/**
	 * Public post document, or null.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, mixed>|null
	 */
	private function from_post( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		if ( ! $this->is_public_post( $post ) ) {
			return null;
		}

		$exporter = new Markdown_Exporter();
		$doc      = $exporter->export_post( $post_id );
		if ( ! is_array( $doc ) ) {
			return null;
		}

		$filtered = apply_filters( 'chathearth_current_page', $doc );

		return is_array( $filtered ) ? $filtered : null;
	}

	/**
	 * Public term document, or null.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return array<string, mixed>|null
	 */
	private function from_term( int $term_id, string $taxonomy ): ?array {
		if ( '' === $taxonomy || ! is_taxonomy_viewable( $taxonomy ) ) {
			return null;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		$exporter = new Markdown_Exporter();
		$doc      = $exporter->export_term( $term_id, $taxonomy );
		if ( ! is_array( $doc ) ) {
			return null;
		}

		$filtered = apply_filters( 'chathearth_current_page', $doc );

		return is_array( $filtered ) ? $filtered : null;
	}

	/**
	 * Match a same-site URL to a public term archive.
	 *
	 * @param string $url URL.
	 * @return array<string, mixed>|null
	 */
	private function term_from_url( string $url ): ?array {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$slug = basename( untrailingslashit( $path ) );
		if ( '' === $slug ) {
			return null;
		}

		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$term = get_term_by( 'slug', $slug, (string) $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) || ! $this->urls_match( $url, (string) $link ) ) {
				continue;
			}
			$doc = $this->from_term( (int) $term->term_id, (string) $taxonomy );
			if ( is_array( $doc ) ) {
				return $doc;
			}
		}

		return null;
	}

	/**
	 * Store citation and optional product card.
	 *
	 * @param array<string, mixed> $doc Document.
	 * @return array<string, mixed>
	 */
	private function remember( array $doc ): array {
		$title = isset( $doc['title'] ) ? (string) $doc['title'] : '';
		$url   = isset( $doc['url'] ) ? (string) $doc['url'] : '';
		$type  = isset( $doc['post_type'] ) && '' !== (string) $doc['post_type']
			? (string) $doc['post_type']
			: (string) ( $doc['object_type'] ?? 'content' );

		if ( '' !== $url ) {
			$this->source = array(
				'title' => $title,
				'url'   => $url,
				'type'  => $type,
			);
		}

		$object_id = isset( $doc['object_id'] ) ? (int) $doc['object_id'] : 0;
		if ( $object_id > 0 && 'product' === $type ) {
			$product = ( new Product_Catalog() )->get_public_product( $object_id );
			if ( is_array( $product ) ) {
				$this->product = $product;
			}
		}

		return $doc;
	}

	/**
	 * Published, publicly viewable, not password-protected.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function is_public_post( \WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		if ( '' !== (string) $post->post_password ) {
			return false;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}
		if ( function_exists( 'is_post_publicly_viewable' ) && ! is_post_publicly_viewable( $post ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Keep only http(s) URLs on this site.
	 *
	 * @param string $url Raw URL.
	 */
	private function same_site_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) ) {
			return '';
		}

		if ( empty( $parts['host'] ) ) {
			$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
			$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

			return $this->same_site_url( home_url( $path . $query ) );
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		$site_host = isset( $home['host'] ) ? strtolower( (string) $home['host'] ) : '';
		$host      = strtolower( (string) $parts['host'] );
		if ( '' === $site_host || $host !== $site_host ) {
			return '';
		}

		return $url;
	}

	/**
	 * Compare host + path, ignoring trailing slashes and query strings.
	 *
	 * @param string $left  URL.
	 * @param string $right URL.
	 */
	private function urls_match( string $left, string $right ): bool {
		$a = $this->url_key( $left );
		$b = $this->url_key( $right );

		return '' !== $a && $a === $b;
	}

	/**
	 * Host + path key.
	 *
	 * @param string $url URL.
	 */
	private function url_key( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$path = isset( $parts['path'] ) ? untrailingslashit( (string) $parts['path'] ) : '';
		if ( '' === $path ) {
			$path = '/';
		}

		return $host . $path;
	}

	/**
	 * Truncate markdown for the prompt budget.
	 *
	 * @param string $markdown Markdown.
	 */
	private function truncate( string $markdown ): string {
		if ( mb_strlen( $markdown, 'UTF-8' ) <= self::MAX_CHARS ) {
			return $markdown;
		}

		return mb_substr( $markdown, 0, self::MAX_CHARS, 'UTF-8' ) . "\n…";
	}

	/**
	 * Current front-end request URL.
	 */
	private static function current_request_url(): string {
		return home_url( add_query_arg( array() ) );
	}

	/**
	 * Decode HTML entities in titles.
	 *
	 * @param string $text Text.
	 */
	private static function decode( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	}
}
