<?php
/**
 * Plugin Name:       MZM Current Year
 * Description:       Displays the current year with the [mzm-current-year] shortcode.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            MZM
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mzm-current-year
 *
 * @package Mzm_Current_Year
 */

defined( 'ABSPATH' ) || exit;

define( 'MZM_CURRENT_YEAR_VERSION', '0.1.0' );
define( 'MZM_CURRENT_YEAR_FILE', __FILE__ );
define( 'MZM_CURRENT_YEAR_PATH', plugin_dir_path( __FILE__ ) );
define( 'MZM_CURRENT_YEAR_URL', plugin_dir_url( __FILE__ ) );

require_once MZM_CURRENT_YEAR_PATH . 'includes/class-mzm-current-year-plugin.php';

/**
 * Returns the shared plugin instance.
 *
 * @return Mzm_Current_Year_Plugin
 */
function mzm_current_year() {
	return Mzm_Current_Year_Plugin::instance();
}

mzm_current_year()->register();
