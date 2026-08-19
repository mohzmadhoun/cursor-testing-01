<?php
/**
 * Add WooCommerce products to the visitor cart from chat.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Commerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;

/**
 * Thin wrapper around WC()->cart.
 */
final class Cart_Service {

	/**
	 * Whether cart operations can run.
	 */
	public static function is_available(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Cart URL when WooCommerce is active.
	 */
	public static function cart_url(): string {
		if ( function_exists( 'wc_get_cart_url' ) ) {
			return (string) wc_get_cart_url();
		}

		return home_url( '/' );
	}

	/**
	 * Checkout URL when WooCommerce is active.
	 */
	public static function checkout_url(): string {
		if ( function_exists( 'wc_get_checkout_url' ) ) {
			return (string) wc_get_checkout_url();
		}

		return home_url( '/' );
	}

	/**
	 * Add a product to the current visitor cart.
	 *
	 * @param int $product_id   Product id.
	 * @param int $quantity     Quantity.
	 * @param int $variation_id Optional variation.
	 * @return array<string, mixed>|WP_Error
	 */
	public function add( int $product_id, int $quantity = 1, int $variation_id = 0 ) {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'chathearth_cart_unavailable',
				__( 'The store cart is not available.', 'chathearth' ),
				array( 'status' => 400 )
			);
		}

		$quantity = max( 1, min( 99, $quantity ) );
		$product  = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'is_purchasable' ) ) {
			return new WP_Error(
				'chathearth_cart_product',
				__( 'That product is not available.', 'chathearth' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $product->is_purchasable() || ( method_exists( $product, 'is_in_stock' ) && ! $product->is_in_stock() ) ) {
			return new WP_Error(
				'chathearth_cart_product',
				__( 'That product cannot be added to the cart.', 'chathearth' ),
				array( 'status' => 400 )
			);
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return new WP_Error(
				'chathearth_cart_unavailable',
				__( 'The store cart is not available.', 'chathearth' ),
				array( 'status' => 400 )
			);
		}

		$added = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );
		if ( ! $added ) {
			return new WP_Error(
				'chathearth_cart_failed',
				__( 'Could not add that product to the cart.', 'chathearth' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'added'        => true,
			'key'          => (string) $added,
			'cart_count'   => (int) WC()->cart->get_cart_contents_count(),
			'cart_url'     => self::cart_url(),
			'checkout_url' => self::checkout_url(),
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'quantity'     => $quantity,
		);
	}
}
