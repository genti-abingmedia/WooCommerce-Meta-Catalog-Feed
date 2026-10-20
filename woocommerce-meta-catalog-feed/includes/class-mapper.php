<?php
namespace WCMCF;

defined( 'ABSPATH' ) || exit;

final class Mapper {
	public const COLUMNS = array( 'id', 'title', 'description', 'availability', 'condition', 'link', 'image_link', 'brand', 'price', 'sale_price', 'item_group_id' );

	public function __construct( private string $fallback_brand ) {}

	/** Null means deliberately omitted; WP_Error means invalid catalog data to report. */
	public function row( \WC_Product $product ) {
		if ( 'publish' !== $product->get_status() || ! $product->is_type( array( 'simple', 'variation' ) ) ) {
			return null;
		}
		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;
		if ( $product->is_type( 'variation' ) && ( ! $parent || ! $parent->is_type( 'variable' ) || 'publish' !== $parent->get_status() ) ) {
			return null;
		}
		$visible = $parent ?: $product;
		if ( ! in_array( $visible->get_catalog_visibility(), array( 'visible', 'catalog' ), true ) || 'yes' === $visible->get_meta( '_wcmcf_exclude' ) || 'yes' === $product->get_meta( '_wcmcf_exclude' ) ) {
			return null;
		}

		$regular = $product->get_regular_price( 'edit' );
		if ( '' === $regular ) {
			$regular = $product->get_price( 'edit' );
		}
		if ( ! $this->valid_price( $regular ) || ! $this->valid_price( $product->get_price( 'edit' ) ) ) {
			return new \WP_Error( 'invalid_price', 'Missing or invalid product price.' );
		}
		$image = false;
		foreach ( array_unique( array_filter( array( $product->get_image_id(), $parent ? $parent->get_image_id() : 0 ) ) ) as $image_id ) {
			$candidate = wp_get_attachment_image_url( $image_id, 'full' );
			if ( $this->valid_url( $candidate ) ) {
				// A stable URL revision prompts a fresh Meta fetch without changing every build.
				// Keep the revision independent of plugin releases and product/price edits.
				$revision = '1-' . substr( hash( 'sha256', $candidate . '|' . $image_id . '|' . get_post_field( 'post_modified_gmt', $image_id, 'raw' ) ), 0, 12 );
				$image = add_query_arg( 'wcmcf_image', $revision, $candidate );
				break;
			}
		}
		$link = $product->get_permalink();
		if ( ! $this->valid_url( $image ) ) {
			return new \WP_Error( 'invalid_image', 'Missing or invalid product image URL.' );
		}
		if ( ! $this->valid_url( $link ) ) {
			return new \WP_Error( 'invalid_link', 'Missing or invalid product URL.' );
		}

		$title = $this->text( $parent ? $parent->get_name() : $product->get_name() );
		if ( $parent ) {
			$attributes = $this->text( wc_get_formatted_variation( $product, true, true, false ) );
			if ( '' !== $attributes ) {
				$title .= ' - ' . $attributes;
			}
		}
		$title = $this->truncate( $title, 200 );
		if ( '' === $title ) {
			return new \WP_Error( 'invalid_title', 'Missing product title.' );
		}
		$description = '';
		foreach ( array( $product->get_short_description(), $product->get_description(), $parent ? $parent->get_short_description() : '', $parent ? $parent->get_description() : '', $title ) as $candidate ) {
			$description = $this->text( $candidate );
			if ( '' !== $description ) {
				break;
			}
		}

		$sale = '';
		$effective = (float) $regular;
		$raw_sale = $product->get_sale_price( 'edit' );
		// Explicit dates prevent stale WooCommerce sale-cron state leaking expired discounts.
		$start = $product->get_date_on_sale_from( 'edit' );
		$end = $product->get_date_on_sale_to( 'edit' );
		$now = time();
		if ( $this->valid_price( $raw_sale ) && (float) $raw_sale < (float) $regular && ( ! $start || $start->getTimestamp() <= $now ) && ( ! $end || $end->getTimestamp() >= $now ) && $product->is_on_sale( 'edit' ) ) {
			$effective = (float) $raw_sale;
		}
		$effective = Discounts::price( $product, (float) $regular, $effective );
		if ( $effective < (float) $regular ) {
			$sale = $this->money( $product, $effective );
			if ( $sale === $this->money( $product, $regular ) ) { $sale = ''; }
		}

		return array(
			(string) $product->get_id(), $title, $this->truncate( $description, 9999 ),
			array( 'instock' => 'in stock', 'outofstock' => 'out of stock', 'onbackorder' => 'available for order' )[ $product->get_stock_status() ] ?? 'out of stock',
			'new', esc_url_raw( $link ), esc_url_raw( $image ), $this->brand( $product, $parent ),
			$this->money( $product, $regular ), $sale, $parent ? (string) $parent->get_id() : '',
		);
	}

	private function valid_price( $value ): bool {
		return '' !== $value && is_numeric( $value ) && is_finite( (float) $value ) && (float) $value >= 0;
	}

	private function valid_url( $url ): bool {
		return is_string( $url ) && false !== filter_var( $url, FILTER_VALIDATE_URL ) && in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true );
	}

	private function money( \WC_Product $product, $value ): string {
		// Tax calculations are independent of the visiting customer and geolocation cookies.
		$amount = (float) $value;
		if ( wc_tax_enabled() && $product->is_taxable() ) {
			$rates = \WC_Tax::get_base_tax_rates( $product->get_tax_class() );
			$inclusive = wc_prices_include_tax();
			$tax = array_sum( \WC_Tax::calc_tax( $amount, $rates, $inclusive ) );
			if ( 'incl' === get_option( 'woocommerce_tax_display_shop', 'excl' ) && ! $inclusive ) {
				$amount += $tax;
			} elseif ( 'incl' !== get_option( 'woocommerce_tax_display_shop', 'excl' ) && $inclusive ) {
				$amount -= $tax;
			}
		}
		return number_format( $amount, 2, '.', '' ) . ' ALL';
	}

	private function brand( \WC_Product $product, $parent ): string {
		if ( taxonomy_exists( 'product_brand' ) ) {
			foreach ( array_filter( array( $product->get_id(), $parent ? $parent->get_id() : 0 ) ) as $id ) {
				$brands = wp_get_post_terms( $id, 'product_brand', array( 'fields' => 'names', 'orderby' => 'name', 'order' => 'ASC' ) );
				if ( ! is_wp_error( $brands ) && ! empty( $brands ) ) {
					return $this->text( $brands[0] );
				}
			}
		}
		return $this->fallback_brand;
	}

	private function text( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( strip_shortcodes( $text ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/[\s\x00-\x1F\x7F]+/u', ' ', wp_check_invalid_utf8( $text, true ) ) ?? '' );
	}

	private function truncate( string $text, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length, 'UTF-8' ) : implode( '', array_slice( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array(), 0, $length ) );
	}
}
