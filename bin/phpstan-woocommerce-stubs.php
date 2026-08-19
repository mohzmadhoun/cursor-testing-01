<?php
/**
 * Minimal WooCommerce symbols for PHPStan (the real plugin is not in the analyse path).
 *
 * @package Workspace
 */

if ( ! class_exists( 'WooCommerce' ) ) {
	/**
	 * WooCommerce plugin bootstrap class stub.
	 */
	class WooCommerce {
	}
}

if ( ! class_exists( 'WC_Cart' ) ) {
	/**
	 * WooCommerce cart stub.
	 */
	class WC_Cart {
		/**
		 * Add an item to the cart.
		 *
		 * @param int $product_id   Product id.
		 * @param int $quantity     Quantity.
		 * @param int $variation_id Variation id.
		 * @return string|false
		 */
		public function add_to_cart( $product_id, $quantity = 1, $variation_id = 0 ) {
			unset( $product_id, $quantity, $variation_id );
			return false;
		}

		/**
		 * Cart contents count.
		 */
		public function get_cart_contents_count() {
			return 0;
		}
	}
}

if ( ! class_exists( 'WooCommerce_Runtime' ) ) {
	/**
	 * WC() return stub.
	 */
	class WooCommerce_Runtime {
		/**
		 * Cart.
		 *
		 * @var WC_Cart|null
		 */
		public $cart;

		/**
		 * Session.
		 *
		 * @var mixed
		 */
		public $session;
	}
}

if ( ! function_exists( 'WC' ) ) {
	/**
	 * WooCommerce instance.
	 *
	 * @return WooCommerce_Runtime
	 */
	function WC() {
		return new WooCommerce_Runtime();
	}
}

if ( ! function_exists( 'wc_get_products' ) ) {
	/**
	 * Product query.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<int, mixed>
	 */
	function wc_get_products( $args = array() ) {
		unset( $args );
		return array();
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * Product factory.
	 *
	 * @param mixed $product Product id.
	 * @return object|false
	 */
	function wc_get_product( $product = false ) {
		unset( $product );
		return false;
	}
}

if ( ! function_exists( 'wc_load_cart' ) ) {
	/**
	 * Load the frontend cart.
	 */
	function wc_load_cart() {
	}
}

if ( ! function_exists( 'wc_get_cart_url' ) ) {
	/**
	 * Cart URL.
	 */
	function wc_get_cart_url() {
		return '';
	}
}

if ( ! function_exists( 'wc_get_checkout_url' ) ) {
	/**
	 * Checkout URL.
	 */
	function wc_get_checkout_url() {
		return '';
	}
}

if ( ! function_exists( 'wc_get_page_permalink' ) ) {
	/**
	 * WooCommerce page permalink.
	 *
	 * @param string $page Page id.
	 */
	function wc_get_page_permalink( $page ) {
		unset( $page );
		return '';
	}
}

if ( ! function_exists( 'wc_price' ) ) {
	/**
	 * Format a price for display.
	 *
	 * @param mixed                $price Amount.
	 * @param array<string, mixed> $args  Format args.
	 */
	function wc_price( $price, $args = array() ) {
		unset( $args );
		return (string) $price;
	}
}

if ( ! function_exists( 'wc_placeholder_img_src' ) ) {
	/**
	 * Placeholder image URL.
	 *
	 * @param string $size Image size.
	 */
	function wc_placeholder_img_src( $size = 'woocommerce_thumbnail' ) {
		unset( $size );
		return '';
	}
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	/**
	 * Store currency code.
	 */
	function get_woocommerce_currency() {
		return '';
	}
}
