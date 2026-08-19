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
		$price       = method_exists( $product, 'get_price_html' ) ? wp_strip_all_tags( (string) $product->get_price_html() ) : '';
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
			'id'          => (int) $product->get_id(),
			'name'        => $name,
			'url'         => $url,
			'price'       => $price,
			'purchasable' => $purchasable,
			'in_stock'    => method_exists( $product, 'is_in_stock' ) ? (bool) $product->is_in_stock() : false,
			'type'        => method_exists( $product, 'get_type' ) ? (string) $product->get_type() : 'simple',
			'variations'  => $variations,
		);
	}
}
