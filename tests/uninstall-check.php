<?php
$root = \WCMCF\Storage::root();
$product = wc_get_products( array( 'limit' => 1 ) )[0];
$product_id = $product->get_id();
$product->update_meta_data( '_wcmcf_exclude', 'yes' ); $product->save();
\WCMCF\Plugin::deactivate();
set_transient( 'wcmcf_form_123', array( 'message' => 'Temporary form error' ), 600 );
define( 'WP_UNINSTALL_PLUGIN', 'woocommerce-meta-catalog-feed/woocommerce-meta-catalog-feed.php' );
require WP_PLUGIN_DIR . '/woocommerce-meta-catalog-feed/uninstall.php';
foreach ( array( 'wcmcf_build', 'wcmcf_result', 'wcmcf_enabled', 'wcmcf_fallback_brand', 'wcmcf_selection', 'wcmcf_initial_refresh', 'wcmcf_status' ) as $option ) {
	if ( false !== get_option( $option, false ) ) { throw new RuntimeException( 'Uninstall left option ' . $option ); }
}
if ( is_dir( $root ) || get_post_meta( $product_id, '_wcmcf_exclude', true ) || ! wc_get_product( $product_id ) || 'ALL' !== get_option( 'woocommerce_currency' ) ) {
	throw new RuntimeException( 'Uninstall cleanup must preserve WooCommerce products and currency.' );
}
if ( false !== get_transient( 'wcmcf_form_123' ) ) { throw new RuntimeException( 'Uninstall left an admin form error transient.' ); }
WP_CLI::success( 'Uninstall removed plugin data and preserved WooCommerce products/settings.' );
