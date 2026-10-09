<?php
/** Native WordPress controls, inside a single scoped feed card. */
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap wcmcf">
	<header class="wcmcf-heading">
		<div><h1><?php esc_html_e( 'Meta Catalog Feed', 'woocommerce-meta-catalog-feed' ); ?></h1><p><?php esc_html_e( 'By Gliterin · One CSV URL for your Meta catalog.', 'woocommerce-meta-catalog-feed' ); ?></p></div>
		<p class="wcmcf-timezone"><?php esc_html_e( 'Tirana time', 'woocommerce-meta-catalog-feed' ); ?><span>Europe/Tirane</span></p>
	</header>
	<?php if ( isset( $messages[ $notice ] ) ) : ?>
		<div class="notice <?php echo in_array( $notice, array( 'queue_failed', 'configuration', 'saved_waiting' ), true ) ? 'notice-warning' : 'notice-success'; ?>" role="status"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div>
	<?php endif; ?>
	<?php if ( $error ) : ?><div class="notice notice-error" role="alert"><p><?php echo esc_html( $error->get_error_message() ); ?></p></div><?php endif; ?>
	<?php if ( ! empty( $status['error'] ) ) : ?><div class="notice notice-error" role="alert"><p><?php echo esc_html( $status['error'] ); ?></p></div><?php endif; ?>
	<?php if ( $form_error ) : ?><div class="notice notice-error" role="alert"><p><?php echo esc_html( $form_error['message'] ); ?></p></div><?php endif; ?>
	<section class="wcmcf-card" aria-labelledby="wcmcf-card-title">
		<div class="wcmcf-card-heading">
			<div><h2 id="wcmcf-card-title"><?php esc_html_e( 'Your catalog feed', 'woocommerce-meta-catalog-feed' ); ?></h2><p><?php esc_html_e( 'Choose which products to export. Every generation uses this same URL.', 'woocommerce-meta-catalog-feed' ); ?></p></div>
			<span class="wcmcf-status wcmcf-status--<?php echo esc_attr( $status['state'] ); ?>"><?php echo esc_html( $states[ $status['state'] ] ?? 'Waiting' ); ?></span>
		</div>
		<div class="wcmcf-url-section">
			<label for="wcmcf-feed-url"><?php esc_html_e( 'Permanent feed URL', 'woocommerce-meta-catalog-feed' ); ?></label>
			<div class="wcmcf-url-row"><input id="wcmcf-feed-url" type="url" readonly value="<?php echo esc_attr( \WCMCF\Plugin::feed_url() ); ?>" aria-describedby="wcmcf-url-help"><button type="button" class="button" data-copy-url><?php esc_html_e( 'Copy URL', 'woocommerce-meta-catalog-feed' ); ?></button><?php if ( ! empty( $result['id'] ) && ! $error ) : ?><a class="button" href="<?php echo esc_url( \WCMCF\Plugin::feed_url() ); ?>"><?php esc_html_e( 'Download CSV', 'woocommerce-meta-catalog-feed' ); ?></a><?php endif; ?></div>
			<p id="wcmcf-url-help" class="wcmcf-help"><?php esc_html_e( 'Add this URL to Meta Commerce Manager with an hourly import schedule. No login or OAuth is required.', 'woocommerce-meta-catalog-feed' ); ?></p><span class="screen-reader-text" data-copy-status role="status"></span>
		</div>
		<dl class="wcmcf-metrics">
			<div><dt><?php esc_html_e( 'Exported products', 'woocommerce-meta-catalog-feed' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $result['exported'] ?? 0 ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Skipped products', 'woocommerce-meta-catalog-feed' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $result['skipped'] ?? 0 ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Last successful generation', 'woocommerce-meta-catalog-feed' ); ?></dt><dd class="wcmcf-date"><?php echo esc_html( isset( $result['completed'] ) ? self::date( $result['completed'] ) : 'No completed CSV yet' ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Next scheduled refresh', 'woocommerce-meta-catalog-feed' ); ?></dt><dd class="wcmcf-date"><?php echo esc_html( true === $next ? 'Queued now' : self::date( $next ) ); ?></dd></div>
		</dl>
		<?php if ( $build ) : ?><p class="wcmcf-progress" role="status"><?php echo esc_html( sprintf( 'Generating CSV: %s products processed. The previous completed CSV stays available until this build finishes.', number_format_i18n( $build['exported'] + $build['skipped'] + $build['omitted'] ) ) ); ?></p><?php endif; ?>
		<form class="wcmcf-selection-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wcmcf_save"><?php wp_nonce_field( 'wcmcf_save' ); ?>
			<div class="wcmcf-editor-heading"><h3><?php esc_html_e( 'Products to include', 'woocommerce-meta-catalog-feed' ); ?></h3><span>ALL currency · Hourly refresh</span></div>
			<div class="wcmcf-field">
				<label id="wcmcf-label-selection_type" for="wcmcf-selection-type"><?php esc_html_e( 'Generate products by', 'woocommerce-meta-catalog-feed' ); ?></label>
				<select id="wcmcf-selection-type" name="selection_type" <?php self::field_attributes( 'selection_type', $form_error ); ?>><?php foreach ( \WCMCF\Selection::TYPES as $type => $label ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( $selection['type'], $type ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
				<p id="wcmcf-help-selection_type" class="wcmcf-help"><?php esc_html_e( 'Use one selection mode. Published, catalog-visible products and enabled variations are eligible.', 'woocommerce-meta-catalog-feed' ); ?></p><?php self::field_error( 'selection_type', $form_error ); ?>
			</div>
			<div class="wcmcf-mode-field" data-mode="all" <?php if ( 'all' !== $selection['type'] ) { echo 'hidden'; } ?>><p class="wcmcf-mode-note"><?php esc_html_e( 'Include all eligible products in your store. Out-of-stock products stay in the CSV with their availability.', 'woocommerce-meta-catalog-feed' ); ?></p></div>
			<div class="wcmcf-mode-field wcmcf-field" data-mode="products" <?php if ( 'products' !== $selection['type'] ) { echo 'hidden'; } ?>>
				<label id="wcmcf-label-products" for="wcmcf-products"><?php esc_html_e( 'Search and select products', 'woocommerce-meta-catalog-feed' ); ?></label>
				<select id="wcmcf-products" name="products[]" class="wc-product-search" multiple data-placeholder="Search by product name or SKU…" data-minimum_input_length="2" data-exclude_type="grouped,external" style="width:100%" <?php self::field_attributes( 'products', $form_error ); ?>><?php self::product_options( $selection['products'] ); ?></select>
				<p id="wcmcf-help-products" class="wcmcf-help"><?php esc_html_e( 'Choose one or more products. A variable product includes its enabled variations; selecting an individual variation includes only that variation.', 'woocommerce-meta-catalog-feed' ); ?></p><?php self::field_error( 'products', $form_error ); ?>
			</div>
			<?php foreach ( array( 'categories' => array( 'product_cat', 'Choose categories', 'Any selected category matches. Include child categories using the checkbox below.' ), 'tags' => array( 'product_tag', 'Choose tags', 'Include products assigned to any of the selected WooCommerce product tags.' ), 'brands' => array( 'product_brand', 'Choose brands', 'Include products assigned to any selected WooCommerce brand. The fallback brand setting does not assign brands to products.' ) ) as $mode => $settings ) : ?>
				<div class="wcmcf-mode-field wcmcf-field" data-mode="<?php echo esc_attr( $mode ); ?>" <?php if ( $mode !== $selection['type'] ) { echo 'hidden'; } ?>>
					<label id="wcmcf-label-<?php echo esc_attr( $mode ); ?>" for="wcmcf-<?php echo esc_attr( $mode ); ?>"><?php echo esc_html( $settings[1] ); ?></label>
					<select id="wcmcf-<?php echo esc_attr( $mode ); ?>" name="<?php echo esc_attr( $mode ); ?>[]" class="wc-enhanced-select" multiple data-placeholder="<?php echo esc_attr( $settings[1] ); ?>…" style="width:100%" <?php self::field_attributes( $mode, $form_error ); ?>><?php self::term_options( $settings[0], $selection[ $mode ] ); ?></select>
					<p id="wcmcf-help-<?php echo esc_attr( $mode ); ?>" class="wcmcf-help"><?php echo esc_html( $settings[2] ); ?></p><?php self::field_error( $mode, $form_error ); ?>
					<?php if ( 'categories' === $mode ) : ?><label class="wcmcf-checkbox"><input type="checkbox" name="include_children" value="1" <?php checked( $selection['include_children'] ); ?>><?php esc_html_e( 'Include child categories', 'woocommerce-meta-catalog-feed' ); ?></label><?php endif; ?>
				</div>
			<?php endforeach; ?>
			<noscript><p><?php esc_html_e( 'Enable JavaScript to use product search and switch selection fields.', 'woocommerce-meta-catalog-feed' ); ?></p></noscript>
			<details class="wcmcf-advanced" <?php if ( $selection['exclude_products'] || $selection['exclude_categories'] || ( $form_error && str_starts_with( $form_error['field'], 'exclude_' ) ) ) { echo 'open'; } ?>>
				<summary><?php esc_html_e( 'Exclude products or categories', 'woocommerce-meta-catalog-feed' ); ?></summary>
				<div class="wcmcf-advanced-grid">
					<div class="wcmcf-field"><label id="wcmcf-label-exclude_products" for="wcmcf-exclude-products"><?php esc_html_e( 'Excluded products', 'woocommerce-meta-catalog-feed' ); ?></label><select id="wcmcf-exclude-products" name="exclude_products[]" class="wc-product-search" multiple data-placeholder="Search for products to exclude…" data-minimum_input_length="2" data-exclude_type="grouped,external" style="width:100%" <?php self::field_attributes( 'exclude_products', $form_error ); ?>><?php self::product_options( $selection['exclude_products'] ); ?></select><p id="wcmcf-help-exclude_products" class="wcmcf-help"><?php esc_html_e( 'Excluding a variable product excludes all its variations.', 'woocommerce-meta-catalog-feed' ); ?></p><?php self::field_error( 'exclude_products', $form_error ); ?></div>
					<div class="wcmcf-field"><label id="wcmcf-label-exclude_categories" for="wcmcf-exclude-categories"><?php esc_html_e( 'Excluded categories', 'woocommerce-meta-catalog-feed' ); ?></label><select id="wcmcf-exclude-categories" name="exclude_categories[]" class="wc-enhanced-select" multiple data-placeholder="Choose categories to exclude…" style="width:100%" <?php self::field_attributes( 'exclude_categories', $form_error ); ?>><?php self::term_options( 'product_cat', $selection['exclude_categories'] ); ?></select><p id="wcmcf-help-exclude_categories" class="wcmcf-help"><?php esc_html_e( 'These categories and their child categories are excluded in every selection mode.', 'woocommerce-meta-catalog-feed' ); ?></p><?php self::field_error( 'exclude_categories', $form_error ); ?></div>
				</div><p class="wcmcf-help"><?php esc_html_e( 'Exclusions take priority over your selection. The Exclude from Meta feed checkbox on each product is also respected.', 'woocommerce-meta-catalog-feed' ); ?></p>
			</details>
			<div class="wcmcf-brand-field wcmcf-field"><label id="wcmcf-label-fallback_brand" for="wcmcf-brand"><?php esc_html_e( 'Fallback brand', 'woocommerce-meta-catalog-feed' ); ?></label><input name="fallback_brand" id="wcmcf-brand" class="regular-text" required value="<?php echo esc_attr( $brand ); ?>" <?php self::field_attributes( 'fallback_brand', $form_error ); ?>><p id="wcmcf-help-fallback_brand" class="wcmcf-help"><?php esc_html_e( 'Used in the CSV when a product has no WooCommerce brand.', 'woocommerce-meta-catalog-feed' ); ?></p><?php self::field_error( 'fallback_brand', $form_error ); ?></div>
			<div class="wcmcf-save-row"><?php submit_button( __( 'Save & generate CSV', 'woocommerce-meta-catalog-feed' ), 'primary', 'submit', false ); ?><p class="wcmcf-help"><?php esc_html_e( 'Your saved selection is used for every hourly refresh. The permanent URL stays unchanged.', 'woocommerce-meta-catalog-feed' ); ?></p></div>
		</form>
		<div class="wcmcf-footer">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wcmcf_refresh"><?php wp_nonce_field( 'wcmcf_refresh' ); ?><?php submit_button( __( 'Refresh now', 'woocommerce-meta-catalog-feed' ), 'secondary', 'submit', false, $error ? array( 'disabled' => 'disabled', 'aria-describedby' => 'wcmcf-refresh-help' ) : array() ); ?></form>
			<p id="wcmcf-refresh-help" class="wcmcf-help"><?php echo esc_html( $error ? 'Resolve the configuration error before refreshing.' : 'Refresh the current saved selection without changing settings.' ); ?></p>
		</div>
		<div class="wcmcf-report-section"><p class="wcmcf-help"><?php echo esc_html( sprintf( '%s products omitted by selection, visibility, status, or exclusions. Counts describe the last completed CSV. Check Meta import results in Commerce Manager.', number_format_i18n( $result['omitted'] ?? 0 ) ) ); ?></p><?php self::report( $result ); ?></div>
	</section>
	<p class="wcmcf-page-note"><?php esc_html_e( 'All dates and times use Europe/Tirane, including daylight-saving changes. CSV generation and Meta imports run on separate schedules.', 'woocommerce-meta-catalog-feed' ); ?></p>
</div>
