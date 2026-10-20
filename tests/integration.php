<?php
/** Run with wp eval-file against the disposable test site. */
use WCMCF\Admin;
use WCMCF\Builder;
use WCMCF\Mapper;
use WCMCF\Plugin;
use WCMCF\Storage;

$GLOBALS['wcmcf_checks'] = 0;
function check( bool $condition, string $message ): void {
	$GLOBALS['wcmcf_checks']++;
	if ( ! $condition ) { throw new RuntimeException( 'FAILED: ' . $message ); }
}
function equal( $expected, $actual, string $message ): void {
	check( $expected === $actual, $message . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}
function product( array $properties = array() ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_props( array_merge( array( 'name' => 'Krem, "Qetësi" \\Al', 'status' => 'publish', 'catalog_visibility' => 'visible', 'regular_price' => '1500', 'image_id' => $GLOBALS['image_id'], 'short_description' => "<p>Qetësi &amp; kujdes, me \"cilësi\".</p>\n[feed_test]", 'tax_status' => 'none' ), $properties ) );
	$product->save();
	return $product;
}
function mapped( WC_Product $product ): array {
	$row = ( new Mapper( 'BlissPharma' ) )->row( $product );
	check( is_array( $row ), 'Product has a valid catalog row' );
	return array_combine( Mapper::COLUMNS, $row );
}
function finish_build(): array {
	for ( $attempt = 0; $attempt < 100; $attempt++ ) {
		$state = get_option( 'wcmcf_build', array() );
		if ( ! $state ) { break; }
		Builder::batch( $state['id'], $state['batch'] );
	}
	check( ! get_option( 'wcmcf_build' ), 'Build finishes' );
	$result = get_option( 'wcmcf_result', array() );
	equal( 'ready', get_option( 'wcmcf_status' )['state'], 'Completed generation is ready' );
	return $result;
}
function feed_rows(): array {
	$handle = Plugin::open_feed();
	check( is_resource( $handle ), 'Completed feed can be opened' );
	$header = fgetcsv( $handle, 0, ',', '"', '' );
	equal( array( 'id', 'title', 'description', 'availability', 'condition', 'link', 'image_link', 'brand', 'price', 'sale_price', 'item_group_id' ), $header, 'Feed preserves sample fields and appends sale/group fields' );
	$rows = array();
	while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
		$row = array_combine( $header, $row );
		check( ! isset( $rows[ $row['id'] ] ), 'No duplicate IDs in completed feed' );
		$rows[ $row['id'] ] = $row;
	}
	fclose( $handle );
	return $rows;
}

// Disable queue runners so this suite controls the exact point of each interruption.
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
Builder::cancel();
update_option( 'wcmcf_enabled', 1, false );
update_option( 'woocommerce_currency', 'ALL' );
delete_option( 'wcmcf_initial_refresh' );
add_shortcode( 'feed_test', static fn() => 'Do not execute this shortcode' );
equal( null, Plugin::configuration_error(), 'ALL configuration accepted' );
check( is_wp_error( Plugin::open_feed() ), 'No initial feed before publication' );
Plugin::ensure_schedule();
check( as_has_scheduled_action( Builder::REFRESH, array(), Builder::GROUP ), 'Hourly refresh scheduled' );
Plugin::ensure_schedule();
$pending = as_get_scheduled_actions( array( 'hook' => Builder::REFRESH, 'group' => Builder::GROUP, 'status' => 'pending', 'per_page' => 100 ), 'ids' );
equal( 1, count( $pending ), 'Scheduling is idempotent' );
check( Builder::enqueue(), 'Manual refresh can queue alongside recurring refresh' );
Builder::cancel();

$upload = wp_upload_bits( 'wcmcf-test.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jR5kAAAAASUVORK5CYII=' ) );
check( empty( $upload['error'] ), 'Fixture image written' );
$image_id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'WCMCF fixture', 'post_status' => 'inherit' ), $upload['file'] );
check( ! is_wp_error( $image_id ) && $image_id > 0, 'Fixture attachment created' );
$GLOBALS['image_id'] = $image_id;
$simple = product();
$row = mapped( $simple );
$image_url = $row['image_link'];
check( str_contains( $image_url, '?wcmcf_image=1-' ), 'Image gets a fresh versioned URL for Meta' );
equal( wp_get_attachment_image_url( $image_id, 'full' ), remove_query_arg( 'wcmcf_image', $image_url ), 'Versioning preserves the original image path' );
equal( $image_url, ( new Mapper( 'BlissPharma' ) )->row( $simple )[6], 'Image URL stays stable across mapper instances' );
$original_modified = get_post_field( 'post_modified_gmt', $image_id, 'raw' );
global $wpdb;
$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2026-10-20 12:00:00' ), array( 'ID' => $image_id ) );
clean_post_cache( $image_id );
check( $image_url !== mapped( $simple )['image_link'], 'Attachment modification changes the image revision' );
$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $original_modified ), array( 'ID' => $image_id ) );
clean_post_cache( $image_id );
$image_url_filter = static fn( $url ) => add_query_arg( 'existing', 'keep', $url ) . '#preview';
add_filter( 'wp_get_attachment_url', $image_url_filter );
$queried_image = mapped( $simple )['image_link'];
check( str_contains( $queried_image, 'existing=keep&wcmcf_image=1-' ) && str_ends_with( $queried_image, '#preview' ), 'Image versioning preserves existing query arguments and fragments' );
remove_filter( 'wp_get_attachment_url', $image_url_filter );
equal( '1500.00 ALL', $row['price'], 'ALL price has fixed decimals without thousands separators' );
equal( 'BlissPharma', $row['brand'], 'Fallback store brand' );
equal( 'in stock', $row['availability'], 'In-stock mapping' );
equal( '', $row['item_group_id'], 'Simple products have no group' );
equal( 'Qetësi & kujdes, me "cilësi".', $row['description'], 'HTML/entities/shortcodes cleaned without executing shortcodes' );
$stream = fopen( 'php://temp', 'w+' );
Builder::write_csv( $stream, array( $row['title'], "Line one, \"quotes\"\nLine two \\ path ë ç" ) );
rewind( $stream );
equal( array( $row['title'], "Line one, \"quotes\"\nLine two \\ path ë ç" ), fgetcsv( $stream, 0, ',', '"', '' ), 'CSV handles Albanian, comma, quotes, newline, and backslash' );
fclose( $stream );

$brand_z = wp_insert_term( 'Zeta', 'product_brand' );
$brand_a = wp_insert_term( 'Alpha', 'product_brand' );
wp_set_object_terms( $simple->get_id(), array( (int) $brand_z['term_id'], (int) $brand_a['term_id'] ), 'product_brand' );
equal( 'Alpha', mapped( $simple )['brand'], 'Alphabetical first product brand' );
$simple->set_sale_price( '1200' ); $simple->save();
equal( $image_url, mapped( $simple )['image_link'], 'Product price edits do not change the image URL' );
equal( '1200.00 ALL', mapped( $simple )['sale_price'], 'Active sale price exported' );
equal( '1500.00 ALL', mapped( $simple )['price'], 'Regular price retained for discounts' );
$simple->set_date_on_sale_from( time() + 3600 ); $simple->save();
equal( '', mapped( $simple )['sale_price'], 'Future sale not exported' );
$simple->set_date_on_sale_from( time() - 3600 ); $simple->set_date_on_sale_to( time() - 60 ); $simple->save();
equal( '', mapped( $simple )['sale_price'], 'Expired sale not exported' );
$simple->set_date_on_sale_from( null ); $simple->set_date_on_sale_to( null ); $simple->save();
$simple->set_stock_status( 'outofstock' ); $simple->save();
equal( 'out of stock', mapped( $simple )['availability'], 'Out-of-stock mapping' );
$simple->set_stock_status( 'onbackorder' ); $simple->save();
equal( 'available for order', mapped( $simple )['availability'], 'Backorder mapping' );
$simple->set_stock_status( 'instock' ); $simple->save();

$hidden = product( array( 'catalog_visibility' => 'hidden' ) );
$search = product( array( 'catalog_visibility' => 'search' ) );
$draft = product( array( 'status' => 'draft' ) );
$excluded = product(); $excluded->update_meta_data( '_wcmcf_exclude', 'yes' ); $excluded->save();
foreach ( array( $hidden, $search, $draft, $excluded ) as $omitted ) {
	equal( null, ( new Mapper( 'BlissPharma' ) )->row( $omitted ), 'Visibility/status/manual exclusion respected' );
}
$bad_image = product( array( 'image_id' => 0 ) );
equal( 'invalid_image', ( new Mapper( 'BlissPharma' ) )->row( $bad_image )->get_error_code(), 'Missing image reported' );
$bad_price = product( array( 'regular_price' => '' ) );
equal( 'invalid_price', ( new Mapper( 'BlissPharma' ) )->row( $bad_price )->get_error_code(), 'Missing price reported' );
$bad_url = product();
$url_filter = static fn() => 'javascript:alert(1)';
add_filter( 'post_type_link', $url_filter );
equal( 'invalid_link', ( new Mapper( 'BlissPharma' ) )->row( $bad_url )->get_error_code(), 'Unsafe product URL rejected' );
remove_filter( 'post_type_link', $url_filter );
$zero = product( array( 'regular_price' => '0' ) );
equal( '0.00 ALL', mapped( $zero )['price'], 'Zero price remains distinct from missing price' );

$parent = new WC_Product_Variable();
$parent->set_name( 'Vitaminë' ); $parent->set_status( 'publish' ); $parent->set_image_id( $image_id ); $parent->set_description( 'Parent description' );
$attribute = new WC_Product_Attribute(); $attribute->set_name( 'Size' ); $attribute->set_options( array( 'Small', 'Large' ) ); $attribute->set_variation( true ); $parent->set_attributes( array( $attribute ) );
$parent->save();
wp_set_object_terms( $parent->get_id(), array( (int) $brand_z['term_id'] ), 'product_brand' );
$variation = new WC_Product_Variation();
$variation->set_parent_id( $parent->get_id() ); $variation->set_status( 'publish' ); $variation->set_regular_price( '2500' ); $variation->set_tax_status( 'none' ); $variation->set_attributes( array( 'size' => 'Small' ) ); $variation->save();
$disabled = new WC_Product_Variation();
$disabled->set_parent_id( $parent->get_id() ); $disabled->set_status( 'private' ); $disabled->set_regular_price( '2500' ); $disabled->set_attributes( array( 'size' => 'Large' ) ); $disabled->save();
WC_Product_Variable::sync( $parent->get_id() );
$variation_row = mapped( $variation );
equal( (string) $parent->get_id(), $variation_row['item_group_id'], 'Variation grouped by parent ID' );
check( str_contains( $variation_row['title'], 'Small' ), 'Variation title includes attributes' );
check( str_contains( $variation_row['link'], 'attribute_size=Small' ), 'Variation link selects attributes' );
equal( 'Zeta', $variation_row['brand'], 'Variation inherits parent brand' );
equal( $image_url, $variation_row['image_link'], 'Variation inherits parent image and its stable revision' );
$variation_upload = wp_upload_bits( 'wcmcf-variation.png', null, file_get_contents( $upload['file'] ) );
$variation_image = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Variation image', 'post_status' => 'inherit' ), $variation_upload['file'] );
$variation->set_image_id( $variation_image ); $variation->save();
$variation_image_url = mapped( $variation )['image_link'];
equal( wp_get_attachment_image_url( $variation_image, 'full' ), remove_query_arg( 'wcmcf_image', $variation_image_url ), 'Variation-specific image takes precedence' );
check( $image_url !== $variation_image_url, 'Variation image has its own revision' );
$variation->set_image_id( 99999999 ); $variation->save();
equal( $image_url, mapped( $variation )['image_link'], 'Invalid variation attachment falls back to valid parent image and revision' );
$variation->set_image_id( $variation_image ); $variation->save();
wp_set_object_terms( $variation->get_id(), array( (int) $brand_a['term_id'] ), 'product_brand' );
equal( 'Alpha', mapped( $variation )['brand'], 'Variation-specific brand takes precedence' );
wp_set_object_terms( $variation->get_id(), array(), 'product_brand' );
equal( 'Parent description', $variation_row['description'], 'Variation inherits parent description' );
equal( null, ( new Mapper( 'BlissPharma' ) )->row( $parent ), 'Variable parent never exported as a row' );
equal( null, ( new Mapper( 'BlissPharma' ) )->row( $disabled ), 'Disabled variation never exported' );
$parent->update_meta_data( '_wcmcf_exclude', 'yes' ); $parent->save();
equal( null, ( new Mapper( 'BlissPharma' ) )->row( $variation ), 'Parent exclusion covers variations' );
$parent->update_meta_data( '_wcmcf_exclude', 'no' ); $parent->save();
$variation->update_meta_data( '_wcmcf_exclude', 'yes' ); $variation->save();
equal( null, ( new Mapper( 'BlissPharma' ) )->row( $variation ), 'Individual variation exclusion' );
$variation->update_meta_data( '_wcmcf_exclude', 'no' ); $variation->save();

// Tax display uses store rates even when the current customer's location differs.
update_option( 'woocommerce_calc_taxes', 'yes' ); update_option( 'woocommerce_default_country', 'AL' );
update_option( 'woocommerce_prices_include_tax', 'no' ); update_option( 'woocommerce_tax_display_shop', 'incl' );
WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'AL', 'tax_rate_state' => '', 'tax_rate' => '20.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ) );
WC_Cache_Helper::invalidate_cache_group( 'taxes' );
$taxed = product( array( 'regular_price' => '1000', 'sale_price' => '800', 'tax_status' => 'taxable' ) );
WC()->customer = new WC_Customer(); WC()->customer->set_billing_country( 'US' ); WC()->customer->set_is_vat_exempt( true );
equal( '1200.00 ALL', mapped( $taxed )['price'], 'Store-base VAT included regardless of visiting customer' );
equal( '960.00 ALL', mapped( $taxed )['sale_price'], 'Discount includes store-base VAT' );
update_option( 'woocommerce_prices_include_tax', 'yes' ); update_option( 'woocommerce_tax_display_shop', 'excl' );
equal( '833.33 ALL', mapped( $taxed )['price'], 'VAT removed from inclusive stored price when shop displays exclusive prices' );
update_option( 'woocommerce_tax_display_shop', 'incl' );
equal( '1000.00 ALL', mapped( $taxed )['price'], 'Inclusive price unchanged when shop displays inclusive prices' );
update_option( 'woocommerce_calc_taxes', 'no' );

// Force several real batches and more than one report page.
for ( $index = 0; $index < 105; $index++ ) { product( array( 'name' => 'Batch product ' . $index ) ); }
for ( $index = 0; $index < 52; $index++ ) { product( array( 'name' => 'Missing image ' . $index, 'image_id' => 0 ) ); }
update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
$simple->set_stock_status( 'outofstock' ); $simple->save();
Builder::start();
$initial = get_option( 'wcmcf_build' );
check( ! empty( $initial['id'] ), 'Initial generation starts' );
Builder::start();
equal( $initial['id'], get_option( 'wcmcf_build' )['id'], 'Overlapping starts reuse active build' );
Builder::batch( $initial['id'], 0 );
equal( 1, get_option( 'wcmcf_build' )['batch'], 'First batch advances progress' );
check( is_wp_error( Plugin::open_feed() ), 'Partial first generation is never downloadable' );
// Simulate death after durable batch write but before committing its state.
as_unschedule_all_actions( Builder::BATCH, null, Builder::GROUP );
update_option( 'wcmcf_build', $initial, false );
Builder::batch( $initial['id'], 0 );
$result = finish_build();
$rows = feed_rows();
equal( $image_url, $rows[ $simple->get_id() ]['image_link'], 'Published CSV contains the versioned image URL' );
equal( count( $rows ), $result['exported'], 'Published counts match CSV' );
equal( 54, $result['skipped'], 'All missing images and prices are reported' );
check( isset( $rows[ $simple->get_id() ] ), 'Out-of-stock product retained despite WooCommerce hide-out-of-stock setting' );
equal( 'out of stock', $rows[ $simple->get_id() ]['availability'], 'Completed feed has latest stock' );
check( isset( $rows[ $variation->get_id() ] ), 'Variation queried through WooCommerce API and exported' );
check( ! isset( $rows[ $parent->get_id() ] ) && ! isset( $rows[ $disabled->get_id() ] ), 'Parent and disabled variation absent' );

$pinned = Plugin::open_feed();
Builder::start();
$active = get_option( 'wcmcf_build' );
equal( $result['id'], get_option( 'wcmcf_result' )['id'], 'Existing feed stays published during refresh' );
$simple->set_regular_price( '1700' ); $simple->set_sale_price( '' ); $simple->save();
$zero->delete( true );
finish_build();
$updated = feed_rows();
equal( $rows[ $simple->get_id() ]['image_link'], $updated[ $simple->get_id() ]['image_link'], 'Completed rebuild keeps unchanged image URLs stable' );
equal( '1700.00 ALL', $updated[ $simple->get_id() ]['price'], 'Product edits appear in next generation' );
check( ! isset( $updated[ $zero->get_id() ] ), 'Deleted product removed from next full feed' );
check( str_contains( stream_get_contents( $pinned ), '1500.00 ALL' ), 'In-progress old download remains a consistent snapshot' ); fclose( $pinned );

Builder::start();
$abandoned = get_option( 'wcmcf_build' ); $abandoned['updated'] = time() - Builder::STALE_SECONDS - 1; update_option( 'wcmcf_build', $abandoned, false );
Builder::start();
check( $abandoned['id'] !== get_option( 'wcmcf_build' )['id'], 'Abandoned build restarts with new generation' );
check( ! is_dir( Storage::generation( $abandoned['id'] ) ), 'Abandoned artifacts cleaned' );
finish_build();

// An unwritable generation fails safely and preserves the prior completed CSV.
Builder::start();
$failed = get_option( 'wcmcf_build' );
$before_failure = get_option( 'wcmcf_result' )['id'];
chmod( Storage::generation( $failed['id'] ), 0555 );
Builder::batch( $failed['id'], 0 );
chmod( Storage::generation( $failed['id'] ), 0755 );
equal( 'failed', get_option( 'wcmcf_status' )['state'], 'Unwritable build recorded as failure' );
equal( $before_failure, get_option( 'wcmcf_result' )['id'], 'Failure preserves successful generation' );
check( is_resource( $handle = Plugin::open_feed() ), 'Previous feed remains available after failure' ); fclose( $handle );

// Currency changes stop active builds and downloads without relabeling their data.
Builder::start(); $currency_build = get_option( 'wcmcf_build' );
update_option( 'woocommerce_currency', 'EUR' );
check( is_wp_error( Plugin::open_feed() ), 'Currency change blocks download of stale ALL feed' );
Builder::batch( $currency_build['id'], 0 );
equal( 'failed', get_option( 'wcmcf_status' )['state'], 'Currency change aborts generation' );
equal( $before_failure, get_option( 'wcmcf_result' )['id'], 'Currency change preserves last complete file' );
update_option( 'woocommerce_currency', 'ALL' );

// Capability and nonce checks run before any scheduling or settings mutation.
$die_filter = static function () { return static function ( $message, $title = '', $args = array() ) { throw new RuntimeException( 'Denied: ' . wp_strip_all_tags( $message ) ); }; };
add_filter( 'wp_die_handler', $die_filter, 99 );
wp_set_current_user( 0 );
foreach ( array( 'refresh', 'save', 'page' ) as $method ) {
	try { Admin::$method(); check( false, 'Unauthorized ' . $method . ' must stop' ); } catch ( RuntimeException $error ) { check( str_starts_with( $error->getMessage(), 'Denied:' ), 'Unauthorized ' . $method . ' denied' ); }
}
wp_set_current_user( get_user_by( 'login', 'wcmcf-admin' )->ID );
unset( $_REQUEST['_wpnonce'], $_POST['_wpnonce'] );
$_SERVER['HTTP_REFERER'] = '';
foreach ( array( 'refresh', 'save' ) as $method ) {
	try { Admin::$method(); check( false, 'Missing nonce ' . $method . ' must stop' ); } catch ( RuntimeException $error ) { check( str_starts_with( $error->getMessage(), 'Denied:' ), 'Missing nonce ' . $method . ' denied' ); }
}
remove_filter( 'wp_die_handler', $die_filter, 99 );
$_POST['_wcmcf_exclude'] = 'yes';
Admin::save_product( $bad_url ); $bad_url->save();
equal( 'yes', wc_get_product( $bad_url->get_id() )->get_meta( '_wcmcf_exclude' ), 'Product exclusion save hook persists checkbox' );
unset( $_POST['_wcmcf_exclude'] );
Admin::save_product( $bad_url ); $bad_url->save();
equal( 'no', wc_get_product( $bad_url->get_id() )->get_meta( '_wcmcf_exclude' ), 'Product exclusion save hook clears unchecked box' );
$_POST['wcmcf_variation_exclude'] = array( 0 => 'yes' );
Admin::save_variation( $variation->get_id(), 0 );
equal( 'yes', wc_get_product( $variation->get_id() )->get_meta( '_wcmcf_exclude' ), 'Variation exclusion save hook persists checkbox' );
unset( $_POST['wcmcf_variation_exclude'] );
Admin::save_variation( $variation->get_id(), 0 );
equal( 'no', wc_get_product( $variation->get_id() )->get_meta( '_wcmcf_exclude' ), 'Variation exclusion save hook clears unchecked box' );
$_GET['report_page'] = 2;
ob_start(); Admin::page(); $html = ob_get_clean();
check( str_contains( $html, 'Page 2 of 2' ), 'All skipped-product reasons accessible through report pagination' );
check( str_contains( $html, 'for="wcmcf-feed-url"' ) && str_contains( $html, 'for="wcmcf-brand"' ), 'Settings inputs have explicit labels' );
unset( $_GET['report_page'] );

// Deactivation cancels every plugin job and discards unfinished work, but keeps last feed.
Builder::start(); $deactivated = get_option( 'wcmcf_build' );
Plugin::deactivate();
check( ! get_option( 'wcmcf_build' ), 'Deactivation discards active build state' );
check( ! is_dir( Storage::generation( $deactivated['id'] ) ), 'Deactivation removes unfinished files' );
equal( array(), as_get_scheduled_actions( array( 'group' => Builder::GROUP, 'status' => 'pending', 'per_page' => 1000 ), 'ids' ), 'Deactivation cancels all scheduled jobs' );
check( is_file( Storage::generation( get_option( 'wcmcf_result' )['id'] ) . '/catalog.csv' ), 'Deactivation preserves completed file' );
Plugin::activate(); Plugin::ensure_schedule();
check( ! get_option( 'wcmcf_initial_refresh' ), 'Reactivation queues initial refresh' );
Builder::start(); finish_build();

// A storage-root permission failure is also reported without destroying the feed.
$root = Storage::root(); chmod( $root, 0555 );
Builder::start();
equal( 'failed', get_option( 'wcmcf_status' )['state'], 'Unwritable storage root reported' );
chmod( $root, 0755 );
Builder::start(); finish_build();
equal( array(), $GLOBALS['wpdb']->last_error ? array( $GLOBALS['wpdb']->last_error ) : array(), 'No database errors' );
WP_CLI::success( $GLOBALS['wcmcf_checks'] . ' integration assertions passed.' );
