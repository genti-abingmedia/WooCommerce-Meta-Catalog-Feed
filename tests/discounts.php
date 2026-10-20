<?php
/** Real FlyCart 2.6.18 engine: public product rules, timing, native sales, and CSVs. */
use WCMCF\Builder;
use WCMCF\Discounts;
use WCMCF\Mapper;
use WCMCF\Plugin;
use Wdr\App\Controllers\Base;
use Wdr\App\Controllers\Configuration;
use Wdr\App\Controllers\DiscountCalculator;
use Wdr\App\Controllers\ManageDiscount;
use Wdr\App\Helpers\Rule;
use Wdr\App\Models\DBTable;

$GLOBALS['wcmcf_discount_checks'] = 0;
// FlyCart 2.6.18 writes this cart-only property; declare it in the fixture to
// avoid its unrelated PHP 8.2+ dynamic-property deprecation during cart setup.
class WCMCF_Discount_Cart_Product extends WC_Product_Simple { public $awdr_product_original_price; }
function discount_check( bool $ok, string $message ): void {
	$GLOBALS['wcmcf_discount_checks']++;
	if ( ! $ok ) { throw new RuntimeException( $message ); }
}
function discount_product( array $props = array() ): WC_Product_Simple {
	$p = new WC_Product_Simple();
	$p->set_props( array_merge( array( 'name' => 'FlyCart feed fixture', 'status' => 'publish', 'regular_price' => '3800', 'tax_status' => 'none', 'image_id' => $GLOBALS['wcmcf_discount_image'] ), $props ) ); $p->save(); return $p;
}
function discount_row( WC_Product $p ): array {
	$row = ( new Mapper( 'BlissPharma' ) )->row( $p );
	discount_check( is_array( $row ), 'Product maps successfully' );
	return array_combine( Mapper::COLUMNS, $row );
}
function discount_rule( WC_Product $product, array $overrides = array() ): int {
	$id = ( new Rule() )->save( array_replace_recursive( array(
		'title' => 'Meta feed fixture rule', 'enabled' => 1, 'discount_type' => 'wdr_simple_discount',
		'filters' => array( array( 'type' => 'products', 'method' => 'in_list', 'value' => array( $product->get_id() ) ) ),
		'product_adjustments' => array( 'type' => 'percentage', 'value' => 10 ),
	), $overrides ) );
	discount_check( $id > 0, 'FlyCart rule saved using its own API' );
	return $id;
}
function discount_reload(): void {
	$conditions = ( new Base() )->getAvailableConditions();
	ManageDiscount::$available_rules = ( new Rule() )->getRuleObject( DBTable::getRules( null, null, null, false ), $conditions );
	ManageDiscount::$calculator = new DiscountCalculator( ManageDiscount::$available_rules );
	DiscountCalculator::$filtered_exclusive_rule = false;
}
function discount_config( string $from, string $priority = 'biggest_discount' ): void {
	Configuration::saveConfig( Configuration::DEFAULT_OPTION, array( 'calculate_discount_from' => $from, 'apply_product_discount_to' => $priority, 'show_strikeout_when' => 'show_dynamically' ) );
	( new Configuration() )->setConfig();
}
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
Builder::cancel();
$GLOBALS['wcmcf_discount_image'] = get_posts( array( 'post_type' => 'attachment', 'posts_per_page' => 1, 'fields' => 'ids' ) )[0];
discount_check( '2.6.18' === WDR_VERSION, 'Testing the same FlyCart core version found on the supplied product page' );
discount_config( 'sale_price' );
$p = discount_product(); $id = discount_rule( $p ); discount_reload();
$row = discount_row( $p );
discount_check( '3800.00 ALL' === $row['price'] && '3420.00 ALL' === $row['sale_price'], 'Public ten-percent discount exports to sale_price while preserving regular price' );
discount_check( '' === $p->get_sale_price( 'edit' ) && '3800' === $p->get_price( 'edit' ), 'Rule integration does not persist changes to WooCommerce prices' );
$native = discount_product( array( 'sale_price' => '3000' ) ); discount_rule( $native ); discount_reload();
discount_check( '2700.00 ALL' === discount_row( $native )['sale_price'], 'Sale-price setting applies the rule to the active native sale exactly once' );
discount_config( 'regular_price' );
discount_check( '3420.00 ALL' === discount_row( $native )['sale_price'], 'Regular-price setting exports the actual rule price, without incorrectly choosing the lower native sale' );
discount_config( 'sale_price' );
$native->set_date_on_sale_from( time() + 86400 ); $native->save();
discount_check( '3420.00 ALL' === discount_row( $native )['sale_price'], 'Future native sale does not become the dynamic rule base' );
$native->set_date_on_sale_from( time() - 86400 ); $native->set_date_on_sale_to( time() - 60 ); $native->save();
discount_check( '3420.00 ALL' === discount_row( $native )['sale_price'], 'Expired native sale does not become the dynamic rule base' );
$fixed = discount_product(); discount_rule( $fixed, array( 'product_adjustments' => array( 'type' => 'flat', 'value' => 500 ) ) ); discount_reload();
discount_check( '3300.00 ALL' === discount_row( $fixed )['sale_price'], 'Fixed discount exports correctly' );
$multiple = discount_product(); discount_rule( $multiple ); discount_rule( $multiple, array( 'product_adjustments' => array( 'value' => 20 ) ) ); discount_reload();
discount_check( '3040.00 ALL' === discount_row( $multiple )['sale_price'], 'Biggest-discount priority follows FlyCart configuration' );
discount_config( 'sale_price', 'first' );
discount_check( '3420.00 ALL' === discount_row( $multiple )['sale_price'], 'First-match priority follows FlyCart rule order' );
discount_config( 'sale_price' );
$category = wp_insert_term( 'FlyCart feed category ' . wp_generate_uuid4(), 'product_cat' )['term_id'];
$parent = new WC_Product_Variable(); $parent->set_props( array( 'name' => 'FlyCart variable', 'status' => 'publish', 'image_id' => $GLOBALS['wcmcf_discount_image'], 'category_ids' => array( $category ) ) ); $parent->save();
$v = new WC_Product_Variation(); $v->set_props( array( 'parent_id' => $parent->get_id(), 'status' => 'publish', 'regular_price' => '3800', 'tax_status' => 'none', 'attributes' => array( 'size' => 'Small' ) ) ); $v->save(); WC_Product_Variable::sync( $parent->get_id() );
$v = wc_get_product( $v->get_id() );
discount_rule( $parent, array( 'filters' => array( array( 'type' => 'product_category', 'method' => 'in_list', 'value' => array( $category ) ) ) ) ); discount_reload();
discount_check( '3420.00 ALL' === discount_row( $v )['sale_price'], 'Variation inherits category rule and exports its own discounted price' );
foreach ( array( 1, 2 ) as $minimum ) {
	$bulk = discount_product();
	discount_rule( $bulk, array( 'discount_type' => 'wdr_bulk_discount', 'product_adjustments' => array( 'type' => '', 'value' => '' ), 'bulk_adjustments' => array( 'operator' => 'product', 'ranges' => array( array( 'from' => $minimum, 'to' => '', 'type' => 'percentage', 'value' => 10 ) ) ) ) ); discount_reload();
	discount_check( ( 1 === $minimum ? '3420.00 ALL' : '' ) === discount_row( $bulk )['sale_price'], 'Only quantity-one bulk prices appear in the catalog' );
}
$now = current_time( 'timestamp' );
// Model rules loaded by an administrative runner, including rules not yet valid.
foreach ( array( array( 'date_from' => $now + 3600 ), array( 'date_to' => $now - 60 ), array( 'usage_limits' => 1, 'used_limits' => 1 ), array( 'enabled' => 0 ), array( 'deleted' => 1 ) ) as $change ) {
	$data = clone ManageDiscount::$available_rules[ $id ]->rule;
	foreach ( $change as $key => $value ) { $data->$key = $value; }
	$rule = new Rule( $data, ( new Base() )->getAvailableConditions() );
	$before = DiscountCalculator::$rules;
	DiscountCalculator::$rules = array( $rule );
	discount_check( '' === discount_row( $p )['sale_price'], 'Future, expired, exhausted, disabled, and deleted rules are rejected in background context' );
	DiscountCalculator::$rules = $before;
}
foreach ( array( 'cart_subtotal', 'user_role', 'user_logged_in', 'user_list', 'cart_coupon' ) as $type ) {
	$data = clone ManageDiscount::$available_rules[ $id ]->rule;
	$data->conditions = wp_json_encode( array( array( 'type' => $type, 'options' => array( 'operator' => 'less_than', 'value' => 10000 ) ) ) );
	$rule = new Rule( $data, ( new Base() )->getAvailableConditions() );
	discount_check( ! Discounts::public_rule( true, false, $rule ), 'Additional cart/customer/coupon conditions are not universal catalog prices' );
}
$conditional = discount_product();
discount_rule( $conditional, array( 'conditions' => array( array( 'type' => 'cart_subtotal', 'options' => array( 'operator' => 'less_than', 'value' => 10000, 'calculate_from' => 'from_filter' ) ) ) ) ); discount_reload();
// Even a cart belonging to the worker's logged-in user cannot trigger bulk pricing.
if ( ! WC()->cart ) { wc_load_cart(); }
wp_set_current_user( get_user_by( 'login', 'wcmcf-admin' )->ID );
WC()->cart->set_cart_contents( array( 'wcmcf-worker-item' => array(
	'key' => 'wcmcf-worker-item', 'product_id' => $bulk->get_id(), 'variation_id' => 0,
	'variation' => array(), 'quantity' => 5, 'data' => new WCMCF_Discount_Cart_Product( $bulk->get_id() ),
	'line_subtotal' => 19000, 'line_total' => 19000, 'line_tax' => 0, 'line_subtotal_tax' => 0,
	'line_tax_data' => array( 'subtotal' => array(), 'total' => array() ),
) ) );
discount_check( 3420.0 === (float) apply_filters( 'advanced_woo_discount_rules_get_product_discount_price', 3800, $conditional, 1, 0 ), 'Real engine accepts a subtotal rule against a qualifying worker cart' );
$cart = WC()->cart->get_cart(); $user = get_current_user_id();
$worker_price = $cart['wcmcf-worker-item']['data']->get_price( 'edit' );
discount_check( '' === discount_row( $conditional )['sale_price'], 'Catalog calculation rejects that conditional offer instead of publishing it for everyone' );
discount_check( '' === discount_row( $bulk )['sale_price'], 'Worker cart quantities are ignored' );
discount_check( $cart === WC()->cart->get_cart() && $user === get_current_user_id(), 'Worker user and cart remain unchanged' );
discount_check( $worker_price === WC()->cart->get_cart()['wcmcf-worker-item']['data']->get_price( 'edit' ), 'Worker cart product price is not mutated by catalog calculation' );
discount_check( ! has_filter( 'advanced_woo_discount_rules_do_process_discounts_of_each_rule', array( Discounts::class, 'public_rule' ) ), 'Scoped rule guard is removed after calculation' );
WC()->cart->empty_cart();
// Feed tax display remains authoritative; use untaxed API result and apply VAT once.
update_option( 'woocommerce_calc_taxes', 'yes' ); update_option( 'woocommerce_prices_include_tax', 'no' ); update_option( 'woocommerce_tax_display_shop', 'incl' );
$taxed = discount_product( array( 'regular_price' => '1000', 'tax_status' => 'taxable' ) ); discount_rule( $taxed ); discount_reload();
discount_check( '1080.00 ALL' === discount_row( $taxed )['sale_price'], 'Dynamic sale includes store-base VAT exactly once' );
update_option( 'woocommerce_prices_include_tax', 'yes' ); update_option( 'woocommerce_tax_display_shop', 'excl' );
discount_check( '750.00 ALL' === discount_row( $taxed )['sale_price'], 'Dynamic sale removes VAT for tax-exclusive display' );
update_option( 'woocommerce_calc_taxes', 'no' );
$previous = get_option( 'wcmcf_result' );
$invalid = static fn() => -1;
add_filter( 'advanced_woo_discount_rules_get_product_discount_price', $invalid, 1000 );
Builder::start();
$state = get_option( 'wcmcf_build' ); Builder::batch( $state['id'], $state['batch'] );
discount_check( 'failed' === get_option( 'wcmcf_status' )['state'] && $previous === get_option( 'wcmcf_result' ), 'Bad discount API result fails generation and preserves the completed CSV' );
remove_filter( 'advanced_woo_discount_rules_get_product_discount_price', $invalid, 1000 );
discount_check( ! has_filter( 'advanced_woo_discount_rules_do_process_discounts_of_each_rule', array( Discounts::class, 'public_rule' ) ), 'Scoped filters are removed even on API failure' );
Builder::start();
for ( $attempt = 0; $attempt < 100 && ( $state = get_option( 'wcmcf_build' ) ); $attempt++ ) { Builder::batch( $state['id'], $state['batch'] ); }
$handle = Plugin::open_feed(); $header = fgetcsv( $handle, 0, ',', '"', '' ); $found = false;
while ( false !== ( $csv = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
	$row = array_combine( $header, $csv );
	if ( (string) $p->get_id() === $row['id'] ) { $found = '3800.00 ALL' === $row['price'] && '3420.00 ALL' === $row['sale_price']; }
}
fclose( $handle ); discount_check( $found, 'Completed CSV contains the dynamic discount in sale_price' );
Builder::cancel(); Plugin::ensure_schedule();
WP_CLI::success( 'FlyCart ' . WDR_VERSION . ' integration: ' . $GLOBALS['wcmcf_discount_checks'] . ' checks passed.' );
