<?php
/** Remove this plugin's data on uninstall, without touching products or WooCommerce settings. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-storage.php';
require_once __DIR__ . '/includes/class-builder.php';

\WCMCF\Builder::cancel();
$wcmcf_lock = false;
try {
	$wcmcf_lock = \WCMCF\Storage::lock( true );
	\WCMCF\Storage::cleanup( array() );
} catch ( \Throwable $wcmcf_error ) {
	// An inaccessible uploads directory must not prevent database cleanup.
} finally {
	\WCMCF\Storage::unlock( $wcmcf_lock );
}
try {
	$wcmcf_root = \WCMCF\Storage::root();
	foreach ( array( 'worker.lock', 'index.php', '.htaccess', 'web.config' ) as $wcmcf_file ) {
		wp_delete_file( $wcmcf_root . '/' . $wcmcf_file );
	}
	@rmdir( $wcmcf_root );
} catch ( \Throwable $wcmcf_error ) {
	// Leave inaccessible filesystem data for the server administrator.
}
foreach ( array( 'wcmcf_fallback_brand', 'wcmcf_enabled', 'wcmcf_initial_refresh', 'wcmcf_selection', 'wcmcf_build', 'wcmcf_result', 'wcmcf_status' ) as $wcmcf_option ) {
	delete_option( $wcmcf_option );
}
delete_metadata( 'post', 0, '_wcmcf_exclude', '', true );
// Remove the short-lived admin form errors stored in WordPress's options table.
global $wpdb;
$wcmcf_transients = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_wcmcf_form_' ) . '%' ) );
foreach ( $wcmcf_transients as $wcmcf_transient ) {
	delete_transient( substr( $wcmcf_transient, strlen( '_transient_' ) ) );
}
