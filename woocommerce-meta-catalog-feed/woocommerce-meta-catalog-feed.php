<?php
/**
 * Plugin Name: WooCommerce Meta Catalog Feed
 * Description: One hourly Meta catalog CSV in Albanian lek (ALL), with WooCommerce product selection.
 * Version: 1.2.1
 * Author: Gliterin
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 9.6
 * Text Domain: woocommerce-meta-catalog-feed
 * License: GPL-2.0-or-later
 * Copyright: 2026 Gliterin
 */

defined( 'ABSPATH' ) || exit;

define( 'WCMCF_VERSION', '1.2.1' );
define( 'WCMCF_FILE', __FILE__ );

// Set this before cache plugins have an opportunity to cache a feed response.
if ( isset( $_GET['wcmcf_feed'] ) && '1' === $_GET['wcmcf_feed'] && ! defined( 'DONOTCACHEPAGE' ) ) {
	define( 'DONOTCACHEPAGE', true );
}

require_once __DIR__ . '/includes/class-selection.php';
require_once __DIR__ . '/includes/class-storage.php';
require_once __DIR__ . '/includes/class-discounts.php';
require_once __DIR__ . '/includes/class-mapper.php';
require_once __DIR__ . '/includes/class-builder.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-plugin.php';

register_activation_hook( __FILE__, array( '\WCMCF\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\WCMCF\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\WCMCF\Plugin', 'boot' ), 20 );
