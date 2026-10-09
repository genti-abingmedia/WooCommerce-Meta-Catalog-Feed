<?php
namespace WCMCF;

defined( 'ABSPATH' ) || exit;

final class Admin {
	private const PAGE = 'wcmcf';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_notices', array( self::class, 'dependency_notice' ) );
		add_action( 'admin_post_wcmcf_refresh', array( self::class, 'refresh' ) );
		add_action( 'admin_post_wcmcf_save', array( self::class, 'save' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( self::class, 'product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'save_product' ) );
		add_action( 'woocommerce_variation_options_pricing', array( self::class, 'variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( self::class, 'save_variation' ), 10, 2 );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Meta Catalog Feed', 'woocommerce-meta-catalog-feed' ), __( 'Meta Catalog Feed', 'woocommerce-meta-catalog-feed' ), 'manage_woocommerce', self::PAGE, array( self::class, 'page' ) );
	}

	public static function dependency_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) { return; }
		$error = Plugin::dependency_error();
		if ( $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error->get_error_message() ) . '</p></div>';
		}
	}

	private static function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this feed.', 'woocommerce-meta-catalog-feed' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function redirect( string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'wcmcf_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function refresh(): void {
		self::authorize( 'wcmcf_refresh' );
		if ( Plugin::configuration_error() ) { self::redirect( 'configuration' ); }
		$queued = Builder::enqueue();
		if ( $queued && ! get_option( 'wcmcf_build' ) ) {
			update_option( 'wcmcf_status', array( 'state' => 'queued', 'error' => '', 'updated' => time() ), false );
		}
		self::redirect( $queued ? 'queued' : 'queue_failed' );
	}

	public static function save(): void {
		self::authorize( 'wcmcf_save' );
		$data = wp_unslash( $_POST );
		$brand = sanitize_text_field( $data['fallback_brand'] ?? '' );
		$error = '' === $brand ? new \WP_Error( 'fallback_brand', 'Enter a fallback brand.' ) : null;
		if ( ! $error && Plugin::dependency_error() ) { $error = new \WP_Error( 'configuration', Plugin::dependency_error()->get_error_message() ); }
		if ( ! $error && isset( $data['selection_type'] ) ) {
			$error = Selection::save( $data, $brand );
			$error = is_wp_error( $error ) ? $error : null;
		} elseif ( ! $error ) {
			// Keep the original settings POST working during an upgrade.
			update_option( 'wcmcf_fallback_brand', $brand, false );
		}
		if ( $error ) {
			set_transient( 'wcmcf_form_' . get_current_user_id(), array( 'data' => $data, 'field' => $error->get_error_code(), 'message' => $error->get_error_message() ), 600 );
			self::redirect( 'form_error' );
		}
		$queued = ! Plugin::configuration_error() && Builder::enqueue();
		if ( $queued && ! get_option( 'wcmcf_build' ) ) {
			update_option( 'wcmcf_status', array( 'state' => 'queued', 'error' => '', 'updated' => time() ), false );
		}
		self::redirect( $queued ? 'saved' : 'saved_waiting' );
	}

	public static function assets( string $hook ): void {
		if ( 'woocommerce_page_wcmcf' !== $hook ) { return; }
		$dependencies = array();
		if ( function_exists( 'WC' ) ) {
			// WooCommerce registers SelectWoo's script globally, but its select styles
			// are otherwise only loaded on WooCommerce's own recognized screens.
			wp_enqueue_style( 'wcmcf-select2', WC()->plugin_url() . '/assets/css/select2.css', array(), WC_VERSION );
			$dependencies[] = 'wcmcf-select2';
		}
		wp_enqueue_style( 'wcmcf-admin', plugins_url( 'assets/admin.css', WCMCF_FILE ), $dependencies, WCMCF_VERSION );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_script( 'wcmcf-admin', plugins_url( 'assets/admin.js', WCMCF_FILE ), array( 'jquery', 'wc-enhanced-select' ), WCMCF_VERSION, true );
	}

	public static function product_field(): void {
		woocommerce_wp_checkbox( array( 'id' => '_wcmcf_exclude', 'label' => __( 'Exclude from Meta feed', 'woocommerce-meta-catalog-feed' ), 'description' => __( 'Excluding a variable product also excludes all its variations.', 'woocommerce-meta-catalog-feed' ) ) );
	}

	public static function save_product( \WC_Product $product ): void {
		// WooCommerce verifies its product-save nonce before firing this hook.
		if ( current_user_can( 'edit_post', $product->get_id() ) ) {
			$product->update_meta_data( '_wcmcf_exclude', isset( $_POST['_wcmcf_exclude'] ) ? 'yes' : 'no' );
		}
	}

	public static function variation_field( int $loop, array $variation_data, \WP_Post $variation ): void {
		$product = wc_get_product( $variation->ID );
		woocommerce_wp_checkbox( array( 'id' => 'wcmcf_variation_exclude_' . $loop, 'name' => 'wcmcf_variation_exclude[' . $loop . ']', 'value' => $product ? $product->get_meta( '_wcmcf_exclude' ) : 'no', 'label' => __( 'Exclude from Meta feed', 'woocommerce-meta-catalog-feed' ), 'wrapper_class' => 'form-row form-row-full' ) );
	}

	public static function save_variation( int $variation_id, int $loop ): void {
		// WooCommerce verifies its variation-save nonce before firing this hook.
		$product = wc_get_product( $variation_id );
		if ( $product && current_user_can( 'edit_post', $variation_id ) ) {
			$product->update_meta_data( '_wcmcf_exclude', isset( $_POST['wcmcf_variation_exclude'][ $loop ] ) ? 'yes' : 'no' );
			$product->save_meta_data();
		}
	}

	public static function date( $timestamp ): string {
		return is_numeric( $timestamp ) && (int) $timestamp > 0
			? wp_date( 'j M Y, H:i', (int) $timestamp, new \DateTimeZone( 'Europe/Tirane' ) )
			: __( 'Not scheduled yet', 'woocommerce-meta-catalog-feed' );
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this feed.', 'woocommerce-meta-catalog-feed' ), '', array( 'response' => 403 ) );
		}
		$error = Plugin::configuration_error();
		$result = get_option( 'wcmcf_result', array() );
		$build = get_option( 'wcmcf_build', array() );
		$status = get_option( 'wcmcf_status', array( 'state' => 'waiting', 'error' => '' ) );
		$next = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( Builder::REFRESH, array(), Builder::GROUP ) : false;
		$selection = Selection::get();
		$brand = get_option( 'wcmcf_fallback_brand', 'BlissPharma' );
		$form_error = get_transient( 'wcmcf_form_' . get_current_user_id() );
		if ( $form_error ) {
			delete_transient( 'wcmcf_form_' . get_current_user_id() );
			$draft = $form_error['data'];
			$selection = array_merge( $selection, array_intersect_key( $draft, Selection::defaults() ) );
			$draft_type = is_string( $draft['selection_type'] ?? null ) ? $draft['selection_type'] : '';
			$selection['type'] = isset( Selection::TYPES[ $draft_type ] ) ? $draft_type : $selection['type'];
			$selection['include_children'] = ! empty( $draft['include_children'] );
			$brand = sanitize_text_field( $draft['fallback_brand'] ?? $brand );
		}
		$notice = sanitize_key( $_GET['wcmcf_notice'] ?? '' );
		$messages = array( 'queued' => 'Feed refresh queued. Reload this page to see progress.', 'saved' => 'Settings saved. Your selection is queued for generation at the same URL.', 'saved_waiting' => 'Settings saved. Resolve the configuration error, then select Refresh now.', 'queue_failed' => 'Could not queue the refresh. Check WooCommerce Scheduled Actions.', 'configuration' => 'Resolve the configuration error before refreshing.' );
		$states = array( 'waiting' => 'Waiting for first generation', 'queued' => 'Refresh queued', 'building' => 'Generating CSV', 'ready' => 'Ready', 'failed' => 'Last refresh failed' );
		include dirname( __DIR__ ) . '/views/admin-page.php';
	}

	private static function field_error( string $field, $error ): void {
		if ( $error && $error['field'] === $field ) {
			echo '<p class="wcmcf-field-error" id="wcmcf-error-' . esc_attr( $field ) . '">' . esc_html( $error['message'] ) . '</p>';
		}
	}

	private static function field_attributes( string $field, $error ): void {
		echo ' aria-describedby="wcmcf-help-' . esc_attr( $field ) . ( $error && $error['field'] === $field ? ' wcmcf-error-' . esc_attr( $field ) : '' ) . '"';
		if ( $error && $error['field'] === $field ) { echo ' aria-invalid="true"'; }
	}

	private static function product_options( $ids ): void {
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$id = absint( $id );
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;
			$label = $product ? wp_strip_all_tags( $product->get_formatted_name() ) : sprintf( 'Unavailable product #%d', $id );
			echo '<option value="' . esc_attr( (string) $id ) . '" selected>' . esc_html( $label ) . '</option>';
		}
	}

	private static function term_options( string $taxonomy, $selected_ids ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) { return; }
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
		if ( is_wp_error( $terms ) ) { return; }
		$selected_ids = is_array( $selected_ids ) ? array_map( 'absint', $selected_ids ) : array();
		$existing = array();
		foreach ( $terms as $term ) {
			$existing[] = $term->term_id;
			$label = $term->name;
			if ( 'product_cat' === $taxonomy && $term->parent ) {
				$parents = get_term_parents_list( $term->term_id, $taxonomy, array( 'link' => false, 'separator' => ' / ', 'inclusive' => true ) );
				if ( ! is_wp_error( $parents ) ) { $label = rtrim( $parents, ' /' ); }
			}
			echo '<option value="' . esc_attr( (string) $term->term_id ) . '"' . ( in_array( $term->term_id, $selected_ids, true ) ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
		}
		foreach ( array_diff( $selected_ids, $existing ) as $id ) {
			echo '<option value="' . esc_attr( (string) $id ) . '" selected>' . esc_html( sprintf( 'Unavailable item #%d', $id ) ) . '</option>';
		}
	}

	private static function report( array $result ): void {
		if ( empty( $result['skipped'] ) ) { return; }
		$page = max( 1, min( (int) ceil( $result['skipped'] / 50 ), absint( $_GET['report_page'] ?? 1 ) ) );
		$handle = false;
		try { $handle = @fopen( Storage::generation( $result['id'] ) . '/skipped.jsonl', 'rb' ); } catch ( \Throwable $error ) { /* Show a read error below. */ }
		if ( ! $handle ) { echo '<p>' . esc_html__( 'The skipped-product report is unavailable.', 'woocommerce-meta-catalog-feed' ) . '</p>'; return; }
		echo '<h3>' . esc_html__( 'Skipped products', 'woocommerce-meta-catalog-feed' ) . '</h3><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Product ID', 'woocommerce-meta-catalog-feed' ) . '</th><th scope="col">' . esc_html__( 'Reason', 'woocommerce-meta-catalog-feed' ) . '</th></tr></thead><tbody>';
		$line_number = 0;
		while ( false !== ( $line = fgets( $handle ) ) ) {
			$line_number++;
			if ( $line_number <= ( $page - 1 ) * 50 ) { continue; }
			if ( $line_number > $page * 50 ) { break; }
			$issue = json_decode( $line, true );
			if ( ! is_array( $issue ) ) { continue; }
			echo '<tr><td>' . esc_html( (string) $issue['id'] ) . '</td><td>' . esc_html( $issue['reason'] ) . '</td></tr>';
		}
		fclose( $handle );
		echo '</tbody></table><p>' . esc_html( sprintf( 'Page %d of %d', $page, (int) ceil( $result['skipped'] / 50 ) ) ) . '</p><nav aria-label="' . esc_attr__( 'Skipped products pages', 'woocommerce-meta-catalog-feed' ) . '">';
		foreach ( array( $page - 1 => __( 'Previous page', 'woocommerce-meta-catalog-feed' ), $page + 1 => __( 'Next page', 'woocommerce-meta-catalog-feed' ) ) as $number => $label ) {
			if ( $number >= 1 && $number <= ceil( $result['skipped'] / 50 ) ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'report_page' => $number ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a> ';
			}
		}
		echo '</nav>';
	}
}
