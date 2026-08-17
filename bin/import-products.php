<?php
/**
 * Imports the sample products into WooCommerce.
 *
 * Run through WP-CLI so WordPress and WooCommerce are already loaded:
 *
 *   wp eval-file bin/import-products.php [path/to/products.csv] --user=admin
 *
 * `--user` is required rather than optional: WooCommerce checks
 * `manage_product_terms` before creating product categories and silently drops
 * them when the importer runs without a user.
 *
 * This drives WooCommerce's own CSV importer rather than creating products by
 * hand, so the file is read exactly as the Products > Import screen would read
 * it: category hierarchies, attributes, stock, dimensions and remote images all
 * behave the same way.
 *
 * @package Workspace
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce is not active.' );
}

if ( ! current_user_can( 'manage_product_terms' ) ) {
	WP_CLI::error(
		'No user with `manage_product_terms`. WooCommerce would drop every product'
		. ' category without a word, so pass --user=admin.'
	);
}

$wp_dev_csv = $args[0] ?? dirname( __DIR__ ) . '/data/sample-products.csv';

if ( ! is_readable( $wp_dev_csv ) ) {
	WP_CLI::error( "Cannot read {$wp_dev_csv}" );
}

require_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
require_once WC_ABSPATH . 'includes/admin/importers/class-wc-product-csv-importer-controller.php';

/**
 * Exposes the column mapping the Products > Import screen builds for itself.
 *
 * WooCommerce keeps `auto_map_columns()` protected, and reimplementing the map
 * would mean maintaining a copy of every column name WooCommerce understands.
 */
class Workspace_Product_CSV_Mapper extends WC_Product_CSV_Importer_Controller {

	/**
	 * Maps raw CSV header names to product field keys.
	 *
	 * @param array $raw_headers Header row of the CSV file.
	 * @return array Mapping of column index to field key.
	 */
	public function map_headers( $raw_headers ) {
		return $this->auto_map_columns( $raw_headers, false );
	}
}

// The importer needs a column map. Read the header row first, then let
// WooCommerce map those names to product fields the way the admin screen does.
$wp_dev_header_reader = new WC_Product_CSV_Importer( $wp_dev_csv, array( 'lines' => 1 ) );
$wp_dev_mapper        = new Workspace_Product_CSV_Mapper();
$wp_dev_mapping       = $wp_dev_mapper->map_headers( $wp_dev_header_reader->get_raw_keys() );

// `update_existing` would put the importer in update-only mode, which skips
// every row that does not already match a product. Creating is what a seed
// needs; SKUs are unique, so a second run cannot duplicate anything.
$wp_dev_importer = new WC_Product_CSV_Importer(
	$wp_dev_csv,
	array(
		'mapping'         => $wp_dev_mapping,
		'parse'           => true,
		'update_existing' => false,
		'lines'           => -1,
	)
);

WP_CLI::log( sprintf( 'Importing products from %s', $wp_dev_csv ) );

$wp_dev_results = $wp_dev_importer->import();

foreach ( $wp_dev_results['imported'] as $wp_dev_id ) {
	$wp_dev_product = wc_get_product( $wp_dev_id );
	WP_CLI::log(
		sprintf(
			'  created  %s (%s)',
			$wp_dev_product ? $wp_dev_product->get_name() : $wp_dev_id,
			$wp_dev_product ? $wp_dev_product->get_type() : 'unknown'
		)
	);
}

foreach ( $wp_dev_results['updated'] as $wp_dev_id ) {
	$wp_dev_product = wc_get_product( $wp_dev_id );
	WP_CLI::log( sprintf( '  updated  %s', $wp_dev_product ? $wp_dev_product->get_name() : $wp_dev_id ) );
}

foreach ( $wp_dev_results['failed'] as $wp_dev_error ) {
	WP_CLI::warning( sprintf( '  failed   %s', $wp_dev_error->get_error_message() ) );
}

foreach ( $wp_dev_results['skipped'] as $wp_dev_error ) {
	WP_CLI::warning( sprintf( '  skipped  %s', $wp_dev_error->get_error_message() ) );
}

wc_delete_product_transients();

WP_CLI::success(
	sprintf(
		'%d created, %d updated, %d failed, %d skipped.',
		count( $wp_dev_results['imported'] ),
		count( $wp_dev_results['updated'] ),
		count( $wp_dev_results['failed'] ),
		count( $wp_dev_results['skipped'] )
	)
);
