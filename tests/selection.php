<?php
/** Exercise selection through completed CSVs on a real WooCommerce installation. */
use WCMCF\Admin;
use WCMCF\Builder;
use WCMCF\Plugin;
use WCMCF\Selection;
use WCMCF\Storage;

$GLOBALS['wcmcf_selection_checks'] = 0;
function selection_check( bool $condition, string $message ): void {
	$GLOBALS['wcmcf_selection_checks']++;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function selection_csv_ids(): array {
	$handle = Plugin::open_feed();
	selection_check( is_resource( $handle ), 'Completed CSV is available' );
	fgetcsv( $handle, 0, ',', '"', '' );
	$ids = array();
	while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) ) { $ids[] = (int) $row[0]; }
	fclose( $handle ); sort( $ids ); return $ids;
}
function selection_build( array $data, array $expected ): void {
	selection_check( true === Selection::save( $data, 'BlissPharma' ), 'Selection saves' );
	Builder::start();
	for ( $i = 0; $i < 100 && ( $state = get_option( 'wcmcf_build' ) ); $i++ ) { Builder::batch( $state['id'], $state['batch'] ); }
	selection_check( 'ready' === get_option( 'wcmcf_status' )['state'], 'Selection generation succeeds' );
	sort( $expected ); selection_check( $expected === selection_csv_ids(), 'Completed CSV contains exactly selected eligible IDs' );
}
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
Builder::cancel();
$image = get_posts( array( 'post_type' => 'attachment', 'posts_per_page' => 1, 'fields' => 'ids' ) )[0];
$cat = wp_insert_term( 'Selection parent', 'product_cat' )['term_id'];
$child = wp_insert_term( 'Selection child', 'product_cat', array( 'parent' => $cat ) )['term_id'];
$tag = wp_insert_term( 'Selection tag', 'product_tag' )['term_id'];
$brand = wp_insert_term( 'Selection brand', 'product_brand' )['term_id'];
$own_brand = wp_insert_term( 'Variation brand', 'product_brand' )['term_id'];
$simple = new WC_Product_Simple();
$simple->set_props( array( 'name' => 'Selection Search Product', 'status' => 'publish', 'regular_price' => '1200', 'image_id' => $image, 'category_ids' => array( $cat ), 'tag_ids' => array( $tag ) ) ); $simple->save();
wp_set_object_terms( $simple->get_id(), array( (int) $brand ), 'product_brand' );
$parent = new WC_Product_Variable();
$parent->set_props( array( 'name' => 'Selection Variable Product', 'status' => 'publish', 'image_id' => $image, 'category_ids' => array( $child ), 'tag_ids' => array( $tag ) ) ); $parent->save();
wp_set_object_terms( $parent->get_id(), array( (int) $brand ), 'product_brand' );
$variations = array();
foreach ( array( 'publish', 'publish', 'private' ) as $index => $status ) {
	$v = new WC_Product_Variation();
	$v->set_props( array( 'parent_id' => $parent->get_id(), 'status' => $status, 'regular_price' => '1500', 'attributes' => array( 'size' => (string) $index ) ) ); $v->save(); $variations[] = $v;
}
WC_Product_Variable::sync( $parent->get_id() );
wp_set_object_terms( $variations[1]->get_id(), array( (int) $own_brand ), 'product_brand' );
$s = $simple->get_id(); $p = $parent->get_id(); $v0 = $variations[0]->get_id(); $v1 = $variations[1]->get_id();
$all_ids = array( $s, $v0, $v1 );
$url = Plugin::feed_url();
selection_build( array( 'selection_type' => 'products', 'products' => array( $s, $p, $v0, $p ) ), $all_ids );
selection_check( $url === Plugin::feed_url() && ! str_contains( $url, 'wcmcf_id' ), 'Selection retains the single permanent URL' );
selection_build( array( 'selection_type' => 'products', 'products' => array( $v1 ) ), array( $v1 ) );
selection_build( array( 'selection_type' => 'categories', 'categories' => array( $cat ), 'include_children' => 1 ), $all_ids );
selection_build( array( 'selection_type' => 'categories', 'categories' => array( $cat ) ), array( $s ) );
selection_build( array( 'selection_type' => 'tags', 'tags' => array( $tag ) ), $all_ids );
selection_build( array( 'selection_type' => 'brands', 'brands' => array( $brand ) ), array( $s, $v0 ) );
selection_build( array( 'selection_type' => 'brands', 'brands' => array( $own_brand ) ), array( $v1 ) );
selection_build( array( 'selection_type' => 'products', 'products' => array( $s, $p ), 'exclude_products' => array( $p ) ), array( $s ) );
selection_build( array( 'selection_type' => 'products', 'products' => array( $p ), 'exclude_products' => array( $v1 ) ), array( $v0 ) );
selection_build( array( 'selection_type' => 'tags', 'tags' => array( $tag ), 'exclude_categories' => array( $cat ) ), array() );
$simple->update_meta_data( '_wcmcf_exclude', 'yes' ); $simple->save();
selection_build( array( 'selection_type' => 'products', 'products' => array( $s, $v0 ) ), array( $v0 ) );
$simple->delete_meta_data( '_wcmcf_exclude' ); $simple->save();
$previous = get_option( 'wcmcf_selection' );
foreach ( array( array( 'selection_type' => 'products' ), array( 'selection_type' => 'categories', 'categories' => array( 9999999 ) ), array( 'selection_type' => 'products', 'products' => array( 9999999 ) ), array( 'selection_type' => array( 'all' ) ) ) as $invalid ) {
	selection_check( is_wp_error( Selection::save( $invalid, 'BlissPharma' ) ), 'Invalid selections fail validation' );
	selection_check( $previous === get_option( 'wcmcf_selection' ), 'Invalid selections preserve saved settings' );
}
// Changing the selection supersedes unfinished work but keeps the public CSV readable.
Builder::start(); $old = get_option( 'wcmcf_build' );
selection_check( true === Selection::save( array( 'selection_type' => 'products', 'products' => array( $s ) ), 'BlissPharma' ), 'Save during generation succeeds' );
selection_check( ! get_option( 'wcmcf_build' ) && ! is_dir( Storage::generation( $old['id'] ) ), 'Superseded generation is discarded' );
Builder::batch( $old['id'], 0 );
selection_check( array( $v0 ) === selection_csv_ids(), 'Stale worker cannot replace the last completed feed' );
selection_build( array( 'selection_type' => 'products', 'products' => array( $s ) ), array( $s ) );
$tz = get_option( 'timezone_string' );
selection_check( '9 Oct 2026, 10:29' === Admin::date( strtotime( '2026-10-09 08:29:00 UTC' ) ), 'Tirana summer time is UTC plus two hours' );
selection_check( '9 Jan 2026, 09:29' === Admin::date( strtotime( '2026-01-09 08:29:00 UTC' ) ), 'Tirana winter time is UTC plus one hour' );
selection_check( $tz === get_option( 'timezone_string' ), 'Plugin dates do not modify the WordPress timezone' );
wp_set_current_user( get_user_by( 'login', 'wcmcf-admin' )->ID );
set_transient( 'wcmcf_form_' . get_current_user_id(), array( 'data' => array( 'selection_type' => array( 'all' ) ), 'field' => 'selection_type', 'message' => 'Choose how to select products.' ), 600 );
ob_start(); Admin::page(); $html = ob_get_clean();
selection_check( 1 === substr_count( $html, '<section class="wcmcf-card"' ), 'Admin contains one feed card' );
selection_check( str_contains( $html, 'Europe/Tirane' ) && str_contains( $html, 'aria-invalid="true"' ), 'Timezone and linked validation error render' );
// Restore the whole catalog for the HTTP and scheduler suite.
selection_check( true === Selection::save( array( 'selection_type' => 'all', 'include_children' => 1 ), 'BlissPharma' ), 'All-product mode restores' );
Builder::start();
for ( $i = 0; $i < 100 && ( $state = get_option( 'wcmcf_build' ) ); $i++ ) { Builder::batch( $state['id'], $state['batch'] ); }
Builder::cancel(); Plugin::ensure_schedule();
WP_CLI::success( 'Selection, CSV publication, validation, and Tirana time: ' . $GLOBALS['wcmcf_selection_checks'] . ' checks passed.' );
