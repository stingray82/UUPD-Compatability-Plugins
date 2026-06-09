<?php
/**
 * Plugin Name:       GetPaid > UUPD Updates
 * Plugin URI:        https://reallyusefulplugins.com
 * Description:       Handles updates for plugin meta functionality for the Get Paid UUPD plugin.
 * Version:           1.0
 * Author:            Reallyusefulplugins.com
 * Author URI:        https://reallyusefulplugins.com
 * Text Domain:       get-paid-uupd
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Tested up to:      6.8.3
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Get_Paid_UUPD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Plugin constants.
 */
define( 'GET_PAID_UUPD_VERSION', '1.0' );
define( 'GET_PAID_UUPD_PLUGIN_FILE', __FILE__ );
define( 'GET_PAID_UUPD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'GET_PAID_UUPD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GET_PAID_UUPD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load required files.
 * These are expected to live in the same directory as this file:
 *  - class-updater-api.php
 *  - class-getpaid-plugin-meta.php
 */
require_once GET_PAID_UUPD_PLUGIN_DIR . 'class-live-variation-downloads.php';
require_once GET_PAID_UUPD_PLUGIN_DIR . 'class-updater-api.php';
require_once GET_PAID_UUPD_PLUGIN_DIR . 'class-getpaid-plugin-meta.php';
require_once GET_PAID_UUPD_PLUGIN_DIR . 'class-secure-uploads.php';
require_once GET_PAID_UUPD_PLUGIN_DIR . 'class-get-paid-shortcodes.php';
require_once GET_PAID_UUPD_PLUGIN_DIR . 'class-get-paid-uupd-utilities.php';
require_once GET_PAID_UUPD_PLUGIN_DIR . 'class-get-paid-shortcode-helpers.php';
require_once GET_PAID_UUPD_PLUGIN_DIR . 'class-invoice-email-download-licence.php';

function rup_gpd_uupd_enqueue_styles() {
    // Allow theme / site owner to disable styles entirely.
    // add_filter( 'rup_gpd_uupd_enable_styles', '__return_false' );
    if ( ! apply_filters( 'rup_gpd_uupd_enable_styles', true ) ) {
        return;
    }

    wp_enqueue_style(
        'my-gpd-account',
        plugin_dir_url( __FILE__ ) . 'assets/css/getpaid-uppd.css',
        array(),
        '1.0.0'
    );
}
add_action( 'wp_enqueue_scripts', 'rup_gpd_uupd_enqueue_styles' );


