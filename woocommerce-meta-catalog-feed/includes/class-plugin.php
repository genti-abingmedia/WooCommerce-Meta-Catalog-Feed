<?php
namespace WCMCF;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public static function boot(): void {
		add_action( 'init', array( self::class, 'serve' ), 5 );
		add_action( 'init', array( self::class, 'ensure_schedule' ), 20 );
		add_action( Builder::REFRESH, array( Builder::class, 'start' ) );
		add_action( Builder::BATCH, array( Builder::class, 'batch' ), 10, 2 );
		Admin::register();
	}

	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate WooCommerce Meta Catalog Feed separately on each site, rather than network-wide.', 'woocommerce-meta-catalog-feed' ) );
		}
		add_option( 'wcmcf_fallback_brand', 'BlissPharma', '', false );
		update_option( 'wcmcf_enabled', 1, false );
		update_option( 'wcmcf_initial_refresh', 1, false );
	}

	public static function deactivate(): void {
		$lock = false;
		try {
			$lock = Storage::lock( true );
			update_option( 'wcmcf_enabled', 0, false );
			Builder::cancel();
			$state = get_option( 'wcmcf_build', array() );
			delete_option( 'wcmcf_build' );
			delete_option( 'wcmcf_initial_refresh' );
			if ( $state && ( get_option( 'wcmcf_result', array() )['id'] ?? '' ) !== $state['id'] ) { Storage::remove_generation( $state['id'] ); }
		} catch ( \Throwable $error ) {
			update_option( 'wcmcf_enabled', 0, false );
			Builder::cancel();
			delete_option( 'wcmcf_build' );
			delete_option( 'wcmcf_initial_refresh' );
		} finally {
			Storage::unlock( $lock );
		}
	}

	public static function dependency_error(): ?\WP_Error {
		if ( ! defined( 'WC_VERSION' ) || ! class_exists( '\WooCommerce' ) || version_compare( WC_VERSION, '9.6', '<' ) ) {
			return new \WP_Error( 'woocommerce_required', 'WooCommerce Meta Catalog Feed requires active WooCommerce 9.6 or later.' );
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return new \WP_Error( 'scheduler_required', 'WooCommerce Action Scheduler is not available yet.' );
		}
		return null;
	}

	public static function configuration_error(): ?\WP_Error {
		$error = self::dependency_error();
		if ( $error ) { return $error; }
		if ( 'ALL' !== get_option( 'woocommerce_currency' ) ) {
			return new \WP_Error( 'currency_required', 'Set the WooCommerce base currency to ALL before generating or downloading this feed.' );
		}
		return null;
	}

	public static function ensure_schedule(): void {
		if ( self::dependency_error() || ! get_option( 'wcmcf_enabled' ) ) { return; }
		if ( ! as_has_scheduled_action( Builder::REFRESH, array(), Builder::GROUP ) ) {
			if ( ! as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, Builder::REFRESH, array(), Builder::GROUP, true ) ) {
				update_option( 'wcmcf_status', array( 'state' => 'failed', 'error' => 'Could not schedule the hourly feed refresh.', 'updated' => time() ), false );
			}
		}
		if ( get_option( 'wcmcf_initial_refresh' ) ) {
			// The recurring action uses the same hook, so enqueue the first generation
			// without uniqueness; the builder itself prevents simultaneous builds.
			if ( as_enqueue_async_action( Builder::REFRESH, array(), Builder::GROUP ) ) {
				delete_option( 'wcmcf_initial_refresh' );
			}
		}
	}

	public static function feed_url(): string {
		return add_query_arg( 'wcmcf_feed', '1', home_url( '/' ) );
	}

	/** Opening the file pins the generation for the whole download, even across publication. */
	public static function open_feed() {
		$error = self::configuration_error();
		if ( $error ) { return $error; }
		$result = get_option( 'wcmcf_result', array() );
		if ( empty( $result['id'] ) ) {
			return new \WP_Error( 'feed_pending', 'The initial catalog feed is not ready yet. Please retry shortly.' );
		}
		try {
			$handle = @fopen( Storage::generation( $result['id'] ) . '/catalog.csv', 'rb' );
			return $handle ?: new \WP_Error( 'feed_unavailable', 'The catalog feed is temporarily unavailable. Please retry shortly.' );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'feed_unavailable', 'The catalog feed is temporarily unavailable. Please retry shortly.' );
		}
	}

	public static function serve(): void {
		if ( ! isset( $_GET['wcmcf_feed'] ) || '1' !== $_GET['wcmcf_feed'] ) { return; }
		// Runs after Action Scheduler initialization (init priority 1), before any theme,
		// redirects, authentication UI, or normal page rendering.
		$handle = self::open_feed();
		while ( ob_get_level() > 0 ) { ob_end_clean(); }
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( is_wp_error( $handle ) ) {
			status_header( 503 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			header( 'Retry-After: 300' );
			// Only public configuration/readiness errors; no filesystem or stack details.
			echo $handle->get_error_message();
			exit;
		}
		if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', array( 'GET', 'HEAD' ), true ) ) {
			fclose( $handle );
			status_header( 405 );
			header( 'Allow: GET, HEAD' );
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="meta-catalog-all.csv"' );
		$stat = fstat( $handle );
		if ( $stat ) { header( 'Content-Length: ' . $stat['size'] ); }
		if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { fpassthru( $handle ); }
		fclose( $handle );
		exit;
	}
}
