<?php
/**
 * Public product payload for chat cards and cart.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Commerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only product helper (WooCommerce when present).
 */
final class Product_Catalog {

	/**
	 * Whether WooCommerce product APIs are available.
	 */
	public static function is_available(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Public catalog card, or null.
	 *
	 * @param int $product_id Product id.
	 * @return array<string, mixed>|null
	 */
	public function get_public_product( int $product_id ): ?array {
		if ( $product_id <= 0 || ! self::is_available() ) {
			return null;
		}

		$product = wc_get_product( $product_id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return null;
		}

		$status = get_post_status( $product_id );
		if ( 'publish' !== $status ) {
			return null;
		}

		$name        = method_exists( $product, 'get_name' ) ? (string) $product->get_name() : get_the_title( $product_id );
		$url         = (string) get_permalink( $product_id );
		$price       = $this->format_amount( method_exists( $product, 'get_price' ) ? $product->get_price() : '' );
		$regular     = $this->regular_price_if_on_sale( $product );
		$media       = $this->public_image( $product_id, $product );
		$purchasable = method_exists( $product, 'is_purchasable' ) && method_exists( $product, 'is_in_stock' )
			? ( $product->is_purchasable() && $product->is_in_stock() )
			: false;

		$variations = array();
		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) && method_exists( $product, 'get_available_variations' ) ) {
			$raw = $product->get_available_variations();
			if ( is_array( $raw ) ) {
				foreach ( array_slice( $raw, 0, 30 ) as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$variations[] = array(
						'id'          => isset( $row['variation_id'] ) ? (int) $row['variation_id'] : 0,
						'price'       => isset( $row['display_price'] ) ? (string) $row['display_price'] : '',
						'is_in_stock' => ! empty( $row['is_in_stock'] ),
						'attributes'  => isset( $row['attributes'] ) && is_array( $row['attributes'] ) ? $row['attributes'] : array(),
					);
				}
			}
		}

		return array(
			'id'            => (int) $product->get_id(),
			'name'          => $name,
			'url'           => $url,
			'image'         => $media['url'],
			'image_alt'     => $media['alt'],
			'price'         => $price,
			'regular_price' => $regular,
			'on_sale'       => '' !== $regular,
			'purchasable'   => $purchasable,
			'in_stock'      => method_exists( $product, 'is_in_stock' ) ? (bool) $product->is_in_stock() : false,
			'type'          => method_exists( $product, 'get_type' ) ? (string) $product->get_type() : 'simple',
			'variations'    => $variations,
		);
	}

	/**
	 * Previous (regular) price when the product is on sale.
	 *
	 * @param object $product WooCommerce product.
	 */
	private function regular_price_if_on_sale( $product ): string {
		if ( ! method_exists( $product, 'is_on_sale' ) || ! $product->is_on_sale() ) {
			return '';
		}
		if ( ! method_exists( $product, 'get_regular_price' ) ) {
			return '';
		}

		$regular = $product->get_regular_price();
		$current = method_exists( $product, 'get_price' ) ? $product->get_price() : '';
		if ( '' === (string) $regular || (string) $regular === (string) $current ) {
			return '';
		}

		return $this->format_amount( $regular );
	}

	/**
	 * Catalog image URL and alt text.
	 *
	 * @param int    $product_id Product id.
	 * @param object $product    WooCommerce product.
	 * @return array{url:string,alt:string}
	 */
	private function public_image( int $product_id, $product ): array {
		$url = get_the_post_thumbnail_url( $product_id, 'woocommerce_thumbnail' );
		if ( ! is_string( $url ) || '' === $url ) {
			$url = get_the_post_thumbnail_url( $product_id, 'medium' );
		}
		if ( ( ! is_string( $url ) || '' === $url ) && method_exists( $product, 'get_image_id' ) ) {
			$image_id = (int) $product->get_image_id();
			if ( $image_id > 0 ) {
				$maybe = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
				$url   = is_string( $maybe ) ? $maybe : '';
			}
		}
		if ( ( ! is_string( $url ) || '' === $url ) && function_exists( 'wc_placeholder_img_src' ) ) {
			$url = (string) wc_placeholder_img_src( 'woocommerce_thumbnail' );
		}

		$alt      = '';
		$thumb_id = get_post_thumbnail_id( $product_id );
		if ( $thumb_id ) {
			$alt = (string) get_post_meta( (int) $thumb_id, '_wp_attachment_image_alt', true );
		}
		if ( '' === $alt ) {
			$alt = method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';
		}

		return array(
			'url' => is_string( $url ) ? $url : '',
			'alt' => $alt,
		);
	}

	/**
	 * Plain-text money for chat cards.
	 *
	 * @param mixed $amount Raw amount.
	 */
	private function format_amount( $amount ): string {
		if ( ! is_scalar( $amount ) || '' === (string) $amount ) {
			return '';
		}

		if ( function_exists( 'wc_price' ) ) {
			$html      = (string) wc_price( $amount );
			$plain     = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
			$collapsed = preg_replace( '/\s+/', ' ', $plain );
			if ( ! is_string( $collapsed ) ) {
				$collapsed = $plain;
			}

			return trim( $collapsed );
		}

		return (string) $amount;
	}

	/**
	 * Public products whose names appear in a visitor message.
	 *
	 * Used so comparison and add-to-cart cards can appear even when RAG is off
	 * or retrieval did not return product chunks.
	 *
	 * @param string $message Visitor message.
	 * @param int    $limit   Max products.
	 * @return list<array<string, mixed>>
	 */
	public function find_mentioned( string $message, int $limit = 6 ): array {
		if ( ! self::is_available() || ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$message = trim( $message );
		if ( '' === $message ) {
			return array();
		}

		$limit = max( 1, min( 12, $limit ) );
		$ids   = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => 80,
				'return' => 'ids',
			)
		);

		$found = array();
		foreach ( $ids as $id ) {
			$card = $this->get_public_product( (int) $id );
			if ( ! is_array( $card ) ) {
				continue;
			}
			$name = isset( $card['name'] ) ? (string) $card['name'] : '';
			if ( ! self::message_mentions_name( $message, $name ) ) {
				continue;
			}
			$found[ (int) $id ] = $card;
			if ( count( $found ) >= $limit ) {
				break;
			}
		}

		return array_values( $found );
	}

	/**
	 * Whether a product name appears as a whole token in the message.
	 *
	 * @param string $message Visitor message.
	 * @param string $name    Product name.
	 */
	public static function message_mentions_name( string $message, string $name ): bool {
		$name = trim( $name );
		if ( mb_strlen( $name, 'UTF-8' ) < 3 ) {
			return false;
		}

		$pattern = '/(?<!\p{L})' . preg_quote( $name, '/' ) . '(?!\p{L})/iu';
		return (bool) preg_match( $pattern, $message );
	}
}
