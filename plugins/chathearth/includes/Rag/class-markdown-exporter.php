<?php
/**
 * Build markdown knowledge-base documents from WordPress objects.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports posts, terms, and site/store identity to markdown.
 *
 * @phpstan-type KbDocument array{
 *   source_id: string,
 *   object_type: string,
 *   object_id: int,
 *   post_type: string,
 *   taxonomy: string,
 *   title: string,
 *   url: string,
 *   markdown: string
 * }
 */
final class Markdown_Exporter {

	/**
	 * HTML converter.
	 *
	 * @var Html_To_Markdown
	 */
	private $html;

	/**
	 * Constructor.
	 *
	 * @param Html_To_Markdown|null $html Converter.
	 */
	public function __construct( ?Html_To_Markdown $html = null ) {
		$this->html = $html instanceof Html_To_Markdown ? $html : new Html_To_Markdown();
	}

	/**
	 * Export a published post (page, CPT, product, …).
	 *
	 * @param int $post_id Post id.
	 * @return array<string, mixed>|null
	 */
	public function export_post( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		if ( 'publish' !== $post->post_status ) {
			return null;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return null;
		}

		$title   = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		$url     = (string) get_permalink( $post );
		$body    = $this->post_body( $post );
		$excerpt = trim( (string) $post->post_excerpt );
		$lines   = array(
			'# ' . $title,
			'',
			'URL: ' . $url,
			'Type: ' . $post->post_type,
		);

		$tax_lines = $this->taxonomy_lines_for_post( $post );
		if ( '' !== $tax_lines ) {
			$lines[] = $tax_lines;
		}

		if ( 'product' === $post->post_type ) {
			$product_lines = $this->product_fact_lines( $post_id );
			if ( '' !== $product_lines ) {
				$lines[] = '';
				$lines[] = $product_lines;
			}
		}

		if ( '' !== $excerpt ) {
			$lines[] = '';
			$lines[] = $this->html->convert( $excerpt );
		}

		if ( '' !== $body ) {
			$lines[] = '';
			$lines[] = $body;
		}

		$markdown = $this->with_front_matter(
			array(
				'id'      => 'post:' . $post_id,
				'type'    => $post->post_type,
				'title'   => $title,
				'url'     => $url,
				'updated' => gmdate( 'c', (int) strtotime( $post->post_modified_gmt . ' UTC' ) ),
			),
			implode( "\n", $lines )
		);

		return array(
			'source_id'   => 'post:' . $post_id,
			'object_type' => 'post',
			'object_id'   => $post_id,
			'post_type'   => (string) $post->post_type,
			'taxonomy'    => '',
			'title'       => $title,
			'url'         => $url,
			'markdown'    => $markdown,
		);
	}

	/**
	 * Export a taxonomy term.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string, mixed>|null
	 */
	public function export_term( int $term_id, string $taxonomy ): ?array {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		$title = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
		$link  = get_term_link( $term );
		$url   = is_wp_error( $link ) ? home_url( '/' ) : (string) $link;
		$body  = $this->html->convert( (string) $term->description );

		$lines = array(
			'# ' . $title,
			'',
			'URL: ' . $url,
			'Taxonomy: ' . $taxonomy,
			'Count: ' . (int) $term->count,
		);
		if ( '' !== $body ) {
			$lines[] = '';
			$lines[] = $body;
		}

		$markdown = $this->with_front_matter(
			array(
				'id'       => 'term:' . $term_id,
				'type'     => 'term',
				'taxonomy' => $taxonomy,
				'title'    => $title,
				'url'      => $url,
			),
			implode( "\n", $lines )
		);

		return array(
			'source_id'   => 'term:' . $term_id,
			'object_type' => 'term',
			'object_id'   => $term_id,
			'post_type'   => '',
			'taxonomy'    => $taxonomy,
			'title'       => $title,
			'url'         => $url,
			'markdown'    => $markdown,
		);
	}

	/**
	 * Site identity document (always useful for grounding and RAG).
	 *
	 * @return array<string, mixed>
	 */
	public function export_site_identity(): array {
		$name        = html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );
		$description = html_entity_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' );
		$url         = home_url( '/' );
		$language    = (string) get_bloginfo( 'language' );

		$lines = array(
			'# ' . ( '' !== $name ? $name : 'Website' ),
			'',
			'Home URL: ' . $url,
			'Tagline: ' . $description,
			'Language: ' . $language,
			'',
			'## Public pages',
		);
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 40,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
		if ( empty( $pages ) ) {
			$lines[] = 'No published pages.';
		} else {
			foreach ( $pages as $page ) {
				$lines[] = '- [' . html_entity_decode( get_the_title( $page ), ENT_QUOTES, 'UTF-8' ) . '](' . get_permalink( $page ) . ')';
			}
		}

		$markdown = $this->with_front_matter(
			array(
				'id'    => 'site:identity',
				'type'  => 'site',
				'title' => $name,
				'url'   => $url,
			),
			implode( "\n", $lines )
		);

		return array(
			'source_id'   => 'site:identity',
			'object_type' => 'site',
			'object_id'   => 0,
			'post_type'   => '',
			'taxonomy'    => '',
			'title'       => $name,
			'url'         => $url,
			'markdown'    => $markdown,
		);
	}

	/**
	 * WooCommerce store summary, or null when WooCommerce is not active.
	 *
	 * @return array<string, mixed>|null
	 */
	public function export_woocommerce(): ?array {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_page_permalink' ) ) {
			return null;
		}

		$shop     = (string) wc_get_page_permalink( 'shop' );
		$cart     = function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : '';
		$checkout = function_exists( 'wc_get_checkout_url' ) ? (string) wc_get_checkout_url() : '';
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';

		$count = wp_count_posts( 'product' );
		$total = isset( $count->publish ) ? (int) $count->publish : 0;

		$lines = array(
			'# Store',
			'',
			'Shop: ' . $shop,
			'Cart: ' . $cart,
			'Checkout: ' . $checkout,
			'Currency: ' . $currency,
			'Published products: ' . $total,
			'',
			'## Product categories',
		);

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			$lines[] = 'No product categories.';
		} else {
			foreach ( $terms as $term ) {
				$link    = get_term_link( $term );
				$url     = is_wp_error( $link ) ? '' : (string) $link;
				$lines[] = '- [' . $term->name . '](' . $url . ') (' . (int) $term->count . ')';
			}
		}

		$markdown = $this->with_front_matter(
			array(
				'id'    => 'site:woocommerce',
				'type'  => 'woocommerce',
				'title' => 'Store',
				'url'   => $shop,
			),
			implode( "\n", $lines )
		);

		return array(
			'source_id'   => 'site:woocommerce',
			'object_type' => 'site',
			'object_id'   => 0,
			'post_type'   => 'product',
			'taxonomy'    => '',
			'title'       => 'Store',
			'url'         => $shop,
			'markdown'    => $markdown,
		);
	}

	/**
	 * Write a markdown file under uploads.
	 *
	 * @param array<string, mixed> $document Document row.
	 */
	public function write_file( array $document ): void {
		if ( empty( $document['source_id'] ) || empty( $document['markdown'] ) ) {
			return;
		}

		Schema::ensure_upload_dir();
		$path = Schema::markdown_path( (string) $document['source_id'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- private KB markdown export.
		file_put_contents( $path, (string) $document['markdown'] );
	}

	/**
	 * Delete the markdown file for a source.
	 *
	 * @param string $source_id Source id.
	 */
	public function delete_file( string $source_id ): void {
		$path = Schema::markdown_path( $source_id );
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * YAML-ish front matter plus body.
	 *
	 * @param array<string, string> $meta Front-matter fields.
	 * @param string                $body Markdown body.
	 */
	public function with_front_matter( array $meta, string $body ): string {
		$lines = array( '---' );
		foreach ( $meta as $key => $value ) {
			$safe    = str_replace( array( "\n", "\r" ), ' ', (string) $value );
			$lines[] = $key . ': ' . $safe;
		}
		$lines[] = '---';
		$lines[] = '';
		$lines[] = trim( $body );

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Expand blocks and convert the post body.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function post_body( \WP_Post $post ): string {
		$content = (string) $post->post_content;
		if ( function_exists( 'do_blocks' ) ) {
			$content = do_blocks( $content );
		}
		$content = strip_shortcodes( $content );

		return $this->html->convert( $content );
	}

	/**
	 * Taxonomy labels for a post.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function taxonomy_lines_for_post( \WP_Post $post ): string {
		$taxes = get_object_taxonomies( $post, 'objects' );
		if ( empty( $taxes ) ) {
			return '';
		}

		$parts = array();
		foreach ( $taxes as $taxonomy ) {
			if ( empty( $taxonomy->public ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			if ( ! is_array( $terms ) || empty( $terms ) ) {
				continue;
			}
			$names = array();
			foreach ( $terms as $term ) {
				$names[] = $term->name;
			}
			$parts[] = $taxonomy->labels->name . ': ' . implode( ', ', $names );
		}

		return implode( "\n", $parts );
	}

	/**
	 * WooCommerce facts when the product API is available.
	 *
	 * @param int $post_id Product post id.
	 */
	private function product_fact_lines( int $post_id ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( $post_id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_name' ) ) {
			return '';
		}

		$lines = array( '## Product facts' );
		$sku   = method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';
		$price = method_exists( $product, 'get_price_html' ) ? wp_strip_all_tags( (string) $product->get_price_html() ) : '';
		$stock = '';
		if ( method_exists( $product, 'is_in_stock' ) ) {
			$stock = $product->is_in_stock() ? 'in stock' : 'out of stock';
		}
		$purchasable = ( method_exists( $product, 'is_purchasable' ) && $product->is_purchasable() ) ? 'yes' : 'no';

		$lines[] = 'SKU: ' . ( '' !== $sku ? $sku : 'n/a' );
		$lines[] = 'Price: ' . ( '' !== $price ? $price : 'n/a' );
		$lines[] = 'Stock: ' . ( '' !== $stock ? $stock : 'n/a' );
		$lines[] = 'Purchasable: ' . $purchasable;

		if ( method_exists( $product, 'get_short_description' ) ) {
			$short = $this->html->convert( (string) $product->get_short_description() );
			if ( '' !== $short ) {
				$lines[] = '';
				$lines[] = $short;
			}
		}

		if ( method_exists( $product, 'get_attributes' ) ) {
			$attributes = $product->get_attributes();
			if ( is_array( $attributes ) && ! empty( $attributes ) ) {
				$lines[] = '';
				$lines[] = 'Attributes:';
				foreach ( $attributes as $attribute ) {
					if ( is_object( $attribute ) && method_exists( $attribute, 'get_name' ) ) {
						$name    = (string) $attribute->get_name();
						$value   = method_exists( $attribute, 'get_options' ) ? $attribute->get_options() : array();
						$label   = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
						$lines[] = '- ' . $name . ': ' . $label;
					}
				}
			}
		}

		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) && method_exists( $product, 'get_available_variations' ) ) {
			$variations = $product->get_available_variations();
			if ( is_array( $variations ) && ! empty( $variations ) ) {
				$lines[] = '';
				$lines[] = 'Variations:';
				foreach ( array_slice( $variations, 0, 20 ) as $variation ) {
					if ( ! is_array( $variation ) ) {
						continue;
					}
					$vid     = isset( $variation['variation_id'] ) ? (int) $variation['variation_id'] : 0;
					$vprice  = isset( $variation['display_price'] ) ? (string) $variation['display_price'] : '';
					$lines[] = '- variation_id ' . $vid . ' price ' . $vprice;
				}
			}
		}

		return implode( "\n", $lines );
	}
}
