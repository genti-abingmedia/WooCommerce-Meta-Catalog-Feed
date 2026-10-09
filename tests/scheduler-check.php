<?php
if ( get_option( 'wcmcf_build' ) || 'ready' !== get_option( 'wcmcf_status', array() )['state'] ) {
	throw new RuntimeException( 'Action Scheduler must finish queued refreshes.' );
}
$handle = \WCMCF\Plugin::open_feed();
if ( ! is_resource( $handle ) ) { throw new RuntimeException( 'Scheduled generation must publish a feed.' ); }
if ( ! str_contains( stream_get_contents( $handle ), 'BlissPharma ""Albania""' ) ) {
	throw new RuntimeException( 'Scheduled generation must apply the saved fallback brand.' );
}
fclose( $handle );
WP_CLI::success( 'Real Action Scheduler runner published the refreshed feed and settings.' );
