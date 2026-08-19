<?php
/**
 * Retrieve KB chunks and inject them into the system prompt.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Commerce\Product_Catalog;
use ChatHearth\Options;

/**
 * Query-time RAG.
 */
final class Retriever {

	/**
	 * Shared instance (so the REST controller can read last hits).
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Last citation list.
	 *
	 * @var list<array<string, mixed>>
	 */
	private $last_sources = array();

	/**
	 * Last matched products.
	 *
	 * @var list<array<string, mixed>>
	 */
	private $last_products = array();

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
	 * Register filter.
	 */
	public function register(): void {
		add_filter( 'chathearth_system_prompt', array( $this, 'inject' ), 20, 3 );
	}

	/**
	 * Sources from the last generate call.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function last_sources(): array {
		return $this->last_sources;
	}

	/**
	 * Products from the last generate call.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function last_products(): array {
		return $this->last_products;
	}

	/**
	 * Inject retrieved context and matching catalog products.
	 *
	 * @param string               $prompt  System prompt.
	 * @param string               $message User message.
	 * @param array<string, mixed> $history History.
	 */
	public function inject( string $prompt, string $message, array $history ): string {
		unset( $history );
		$catalog             = new Product_Catalog();
		$products            = array();
		$this->last_sources  = array();
		$this->last_products = array();

		foreach ( $catalog->find_mentioned( $message ) as $card ) {
			$products[ (int) $card['id'] ] = $card;
		}

		if ( Options::is_rag_enabled() ) {
			$hits = $this->search( $message );
			if ( empty( $hits ) ) {
				$prompt .= "\n\n## Retrieved knowledge\nNo matching knowledge-base passages were found. Stay within the website context above. If you cannot answer from that context, say so.\n";
			} else {
				$blocks  = array();
				$sources = array();

				foreach ( $hits as $hit ) {
					$meta  = $hit['meta'];
					$title = isset( $meta['title'] ) ? (string) $meta['title'] : '';
					$url   = isset( $meta['url'] ) ? (string) $meta['url'] : '';
					$type  = isset( $meta['post_type'] ) && '' !== (string) $meta['post_type'] ? (string) $meta['post_type'] : (string) ( $meta['object_type'] ?? 'content' );

					if ( '' !== $url && ! isset( $sources[ $url ] ) ) {
						$sources[ $url ] = array(
							'title' => $title,
							'url'   => $url,
							'type'  => $type,
						);
					}

					$object_id = isset( $meta['object_id'] ) ? (int) $meta['object_id'] : 0;
					if ( $object_id > 0 && ( 'product' === $type || ( isset( $meta['post_type'] ) && 'product' === $meta['post_type'] ) ) ) {
						$product = $catalog->get_public_product( $object_id );
						if ( is_array( $product ) ) {
							$products[ $object_id ] = $product;
						}
					}

					$blocks[] = '### ' . ( '' !== $title ? $title : 'Source' ) . "\n" . ( '' !== $url ? 'URL: ' . $url . "\n" : '' ) . trim( (string) $hit['content'] );
				}

				$this->last_sources = array_values( $sources );

				$joined = implode( "\n\n---\n\n", $blocks );
				if ( mb_strlen( $joined, 'UTF-8' ) > 12000 ) {
					$joined = mb_substr( $joined, 0, 12000, 'UTF-8' );
				}

				$prompt .= "\n\n## Retrieved knowledge\nUse the following passages from this website. Cite them with Markdown links. If they are not enough, say you do not have that information.\n\n" . $joined . "\n";
			}
		}

		$this->last_products = array_values( $products );
		$prompt             .= $this->catalog_prompt( $this->last_products );

		return $prompt;
	}

	/**
	 * Compact catalog facts for comparison and cart.
	 *
	 * @param list<array<string, mixed>> $products Products.
	 */
	private function catalog_prompt( array $products ): string {
		if ( empty( $products ) ) {
			return '';
		}

		$lines = array(
			"\n\n## Matching catalog products",
			'Use these facts for comparisons and cart actions. Include Markdown links. Do not invent prices or stock.',
		);
		foreach ( $products as $product ) {
			$name    = isset( $product['name'] ) ? (string) $product['name'] : 'Product';
			$url     = isset( $product['url'] ) ? (string) $product['url'] : '';
			$price   = isset( $product['price'] ) ? (string) $product['price'] : '';
			$stock   = ! empty( $product['in_stock'] ) ? 'in stock' : 'out of stock';
			$buy     = ! empty( $product['purchasable'] ) ? 'can be added to cart' : 'cannot be added to cart from chat';
			$label   = '' !== $url ? '[' . $name . '](' . $url . ')' : $name;
			$lines[] = '- ' . $label . ' — ' . $price . ' — ' . $stock . ' — ' . $buy;
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Embed the query and search the configured store.
	 *
	 * @param string $message User message.
	 * @return list<array{id:string,score:float,content:string,meta:array<string, mixed>}>
	 */
	public function search( string $message ): array {
		$embedder = new Embedding_Client();
		$vector   = $embedder->embed( $message );
		if ( is_wp_error( $vector ) ) {
			return array();
		}

		$limit = (int) Options::get( 'rag_top_k', 6 );
		$limit = max( 1, min( 12, $limit ) );
		if ( $this->looks_like_comparison( $message ) ) {
			$limit = min( 12, max( $limit, $limit * 2 ) );
		}

		$hits = Vector_Store_Factory::make()->query( $vector, $limit );
		$repo = new Kb_Repository();

		foreach ( $hits as $i => $hit ) {
			$local = $repo->get_chunk( (string) $hit['id'] );
			if ( is_array( $local ) && ! empty( $local['content'] ) ) {
				$hits[ $i ]['content'] = (string) $local['content'];
			}
		}

		return $hits;
	}

	/**
	 * Whether the user is asking for a comparison.
	 *
	 * @param string $message Message.
	 */
	private function looks_like_comparison( string $message ): bool {
		return (bool) preg_match( '/\b(compar(?:e|ison)|vs\.?|versus|difference|better|which (?:one|product))\b/i', $message );
	}
}
