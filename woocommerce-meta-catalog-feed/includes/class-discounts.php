<?php
namespace WCMCF;

defined( 'ABSPATH' ) || exit;

/** FlyCart prices for one item, without a shopper's cart or private offer conditions. */
final class Discounts {
	private const PRICE_HOOK = 'advanced_woo_discount_rules_get_product_discount_price';

	public static function price( \WC_Product $product, float $regular, float $base ): float {
		if ( ! has_filter( self::PRICE_HOOK ) ) { return $base; }
		$copy = clone $product;
		$copy->set_regular_price( (string) $regular );
		$copy->set_price( (string) $base );
		$copy->set_sale_price( $base < $regular ? (string) $base : '' );
		$empty_cart = static fn() => array();
		$raw_price = static fn( $price, $item ) => $item->get_id() === $copy->get_id() ? $base : $price;
		$raw_regular = static fn( $price, $item ) => $item->get_id() === $copy->get_id() ? $regular : $price;
		$guard = array( self::class, 'public_rule' );
		$filters = array(
			array( 'advanced_woo_discount_rules_get_cart', $empty_cart, 1 ),
			array( 'advanced_woo_discount_rules_get_price', $raw_price, 2 ),
			array( 'advanced_woo_discount_rules_get_regular_price', $raw_regular, 2 ),
			array( 'advanced_woo_discount_rules_do_process_discounts_of_each_rule', $guard, 3 ),
		);
		foreach ( $filters as $filter ) { add_filter( $filter[0], $filter[1], PHP_INT_MAX, $filter[2] ); }
		try {
			// FlyCart 2.6+ exposes its manual product calculation through this API.
			// Leave custom_price at zero to respect its Regular price/Sale price setting.
			$price = apply_filters( self::PRICE_HOOK, $base, $copy, 1, 0 );
			if ( false === $price || null === $price ) { return $base; }
			if ( ! is_numeric( $price ) || ! is_finite( (float) $price ) || (float) $price < 0 ) {
				throw new \RuntimeException( 'Discount Rules returned an invalid price for product #' . $product->get_id() . '.' );
			}
			return (float) $price;
		} finally {
			foreach ( $filters as $filter ) { remove_filter( $filter[0], $filter[1], PHP_INT_MAX ); }
		}
	}

	/** Additional conditions cannot be represented as a universal Meta sale price. */
	public static function public_rule( $allowed, $is_cart, $rule ): bool {
		if ( ! $allowed || ! $rule instanceof \Wdr\App\Helpers\Rule ) { return false; }
		$data = $rule->rule;
		if ( ! in_array( $rule->getRuleDiscountType(), array( 'wdr_simple_discount', 'wdr_bulk_discount' ), true ) || $rule->hasConditions() || $rule->hasCartDiscount() ) { return false; }
		if ( ! $rule->isEnabled() || ! empty( $data->deleted ) ) { return false; }
		// FlyCart stores its schedule using WordPress's local timestamp convention.
		// Check it explicitly: background admin runners may load future rules too.
		$now = current_time( 'timestamp' );
		if ( ( ! empty( $data->date_from ) && $data->date_from > $now ) || ( ! empty( $data->date_to ) && $data->date_to < $now ) ) { return false; }
		if ( ! empty( $data->usage_limits ) && (int) ( $data->used_limits ?? 0 ) >= (int) $data->usage_limits ) { return false; }
		return true;
	}
}
