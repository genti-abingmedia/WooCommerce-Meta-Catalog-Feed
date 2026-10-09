<?php
if ( ! is_wp_error( \WCMCF\Plugin::configuration_error() ) || ! is_wp_error( \WCMCF\Plugin::open_feed() ) ) {
	throw new RuntimeException( 'Missing WooCommerce must block generation and download.' );
}
\WCMCF\Plugin::ensure_schedule();
WP_CLI::success( 'Missing WooCommerce handled without a fatal error.' );
