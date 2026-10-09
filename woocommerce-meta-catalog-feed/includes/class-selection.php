<?php
namespace WCMCF;

defined( 'ABSPATH' ) || exit;

/** One saved selection controls the single permanent CSV URL. */
final class Selection {
	public const TYPES = array( 'all' => 'All products', 'products' => 'Selected products', 'categories' => 'Categories', 'tags' => 'Tags', 'brands' => 'Brands' );

	public static function defaults(): array {
		return array( 'type' => 'all', 'products' => array(), 'categories' => array(), 'tags' => array(), 'brands' => array(), 'include_children' => true, 'exclude_products' => array(), 'exclude_categories' => array() );
	}

	public static function get(): array {
		return array_merge( self::defaults(), get_option( 'wcmcf_selection', array() ) );
	}

	private static function ids( $values ): array {
		return is_array( $values ) ? array_values( array_unique( array_filter( array_map( 'absint', $values ) ) ) ) : array();
	}

	/** Empty specific selections must never fall back to exporting the whole store. */
	public static function validate( array $data ) {
		$type = is_string( $data['selection_type'] ?? null ) ? $data['selection_type'] : '';
		if ( ! isset( self::TYPES[ $type ] ) ) { return new \WP_Error( 'selection_type', 'Choose how to select products.' ); }
		$selection = self::defaults();
		$selection['type'] = $type;
		$selection['include_children'] = ! empty( $data['include_children'] );
		foreach ( array( 'products', 'categories', 'tags', 'brands', 'exclude_products', 'exclude_categories' ) as $field ) {
			$selection[ $field ] = ( $field === $type || str_starts_with( $field, 'exclude_' ) ) ? self::ids( $data[ $field ] ?? array() ) : array();
		}
		if ( 'all' !== $type && ! $selection[ $type ] ) {
			return new \WP_Error( $type, 'products' === $type ? 'Search for and select at least one product.' : 'Select at least one item for this selection.' );
		}
		foreach ( array( 'categories' => 'product_cat', 'tags' => 'product_tag', 'brands' => 'product_brand', 'exclude_categories' => 'product_cat' ) as $field => $taxonomy ) {
			foreach ( $selection[ $field ] as $id ) {
				if ( ! term_exists( $id, $taxonomy ) ) { return new \WP_Error( $field, 'A selected item no longer exists. Remove it and save again.' ); }
			}
		}
		foreach ( array( 'products', 'exclude_products' ) as $field ) {
			foreach ( $selection[ $field ] as $id ) {
				$product = wc_get_product( $id );
				if ( ! $product || ! $product->is_type( array( 'simple', 'variable', 'variation' ) ) ) {
					return new \WP_Error( $field, 'Select existing simple products, variable products, or variations.' );
				}
			}
		}
		return $selection;
	}

	/** Save and interrupt superseded work under the same mutex used by the builder. */
	public static function save( array $data, string $brand ) {
		$selection = self::validate( $data );
		if ( is_wp_error( $selection ) ) { return $selection; }
		$lock = false;
		try {
			$lock = Storage::lock( true );
			update_option( 'wcmcf_selection', $selection, false );
			update_option( 'wcmcf_fallback_brand', $brand, false );
			as_unschedule_all_actions( Builder::BATCH, null, Builder::GROUP );
			$build = get_option( 'wcmcf_build', array() );
			delete_option( 'wcmcf_build' );
			if ( $build && ( get_option( 'wcmcf_result', array() )['id'] ?? '' ) !== $build['id'] ) { Storage::remove_generation( $build['id'] ); }
			return true;
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'storage', 'Could not save the selection. Check the uploads directory permissions.' );
		} finally { Storage::unlock( $lock ); }
	}

	private static function terms( array $ids, bool $children ): array {
		$expanded = $ids;
		if ( $children ) {
			foreach ( $ids as $id ) {
				$descendants = get_term_children( $id, 'product_cat' );
				if ( is_wp_error( $descendants ) ) { throw new \RuntimeException( 'Could not read category descendants.' ); }
				$expanded = array_merge( $expanded, $descendants );
			}
		}
		return self::ids( $expanded );
	}

	public static function prepare( array $selection ): array {
		$selection['include_terms'] = self::terms( $selection['categories'], $selection['include_children'] );
		$selection['exclude_terms'] = self::terms( $selection['exclude_categories'], true );
		return $selection;
	}

	public static function candidate_ids( array $selection ): array {
		if ( 'products' === $selection['type'] ) {
			$ids = array();
			foreach ( $selection['products'] as $id ) {
				$product = wc_get_product( $id );
				if ( $product && $product->is_type( 'variable' ) ) { $ids = array_merge( $ids, $product->get_children() ); }
				else { $ids[] = $id; }
			}
			return self::ids( $ids );
		}
		// Taxonomy filters are checked on each product (and its parent for variations),
		// so category inheritance and variation-specific brands behave consistently.
		$ids = array();
		foreach ( array( 'simple', 'variation' ) as $type ) {
			$ids = array_merge( $ids, wc_get_products( array( 'type' => $type, 'status' => 'publish', 'return' => 'ids', 'limit' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) );
		}
		return self::ids( $ids );
	}

	private static function product_terms( int $id, string $taxonomy ): array {
		$terms = get_the_terms( $id, $taxonomy );
		if ( is_wp_error( $terms ) ) { throw new \RuntimeException( 'Could not read product taxonomy assignments.' ); }
		return $terms ? array_map( 'intval', wp_list_pluck( $terms, 'term_id' ) ) : array();
	}

	public static function matches( \WC_Product $product, array $selection ): bool {
		$parent = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		if ( array_intersect( array( $product->get_id(), $parent ), $selection['exclude_products'] ) ) { return false; }
		$categories = array();
		if ( $selection['exclude_terms'] || 'categories' === $selection['type'] ) {
			$categories = self::product_terms( $parent, 'product_cat' );
		}
		if ( array_intersect( $categories, $selection['exclude_terms'] ) ) { return false; }
		switch ( $selection['type'] ) {
			case 'products': return (bool) array_intersect( array( $product->get_id(), $parent ), $selection['products'] );
			case 'categories': return (bool) array_intersect( $categories, $selection['include_terms'] );
			case 'tags': return (bool) array_intersect( self::product_terms( $parent, 'product_tag' ), $selection['tags'] );
			case 'brands':
				if ( ! taxonomy_exists( 'product_brand' ) ) { return false; }
				$brands = self::product_terms( $product->get_id(), 'product_brand' );
				if ( ! $brands && $parent !== $product->get_id() ) { $brands = self::product_terms( $parent, 'product_brand' ); }
				return (bool) array_intersect( $brands, $selection['brands'] );
			default: return true;
		}
	}
}
