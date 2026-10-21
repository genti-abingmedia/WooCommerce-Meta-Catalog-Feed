<?php
/** Run against the disposable WordPress integration site. */
use WCMCF\Builder;
use WCMCF\Plugin;
use WCMCF\Selection;

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$finish = static function () use ( $assert ): void {
	for ( $i = 0; $i < 100 && get_option( 'wcmcf_build' ); $i++ ) {
		$state = get_option( 'wcmcf_build' );
		Builder::batch( $state['id'], $state['batch'] );
	}
	$assert( ! get_option( 'wcmcf_build' ) && 'ready' === get_option( 'wcmcf_status' )['state'], 'Image renewal build completes' );
};
$read = static function (): array {
	$handle = Plugin::open_feed();
	$header = fgetcsv( $handle, 0, ',', '"', '' );
	$rows = array();
	while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
		$row = array_combine( $header, $row );
		$rows[ $row['id'] ] = $row;
	}
	fclose( $handle );
	return $rows;
};

$selection = Selection::get();
$brand = get_option( 'wcmcf_fallback_brand', 'BlissPharma' );
$assert( true === Selection::save( array( 'selection_type' => 'all' ), $brand ), 'All-product fixture selection saved' );
Builder::start(); $finish();
$before = $read();
$published = get_option( 'wcmcf_result' )['id'];
$assert( count( $before ) > Builder::BATCH_SIZE, 'Renewal covers multiple batches' );
Builder::start();
$old = get_option( 'wcmcf_build' );
Builder::batch( $old['id'], 0 );
$assert( Builder::enqueue( true ), 'Manual renewal queues successfully' );
$revision = get_option( 'wcmcf_image_revision' );
$assert( is_string( $revision ) && '' !== $revision, 'Manual renewal persists a revision' );
$assert( ! get_option( 'wcmcf_build' ), 'Renewal cancels superseded partial build' );
$assert( $published === get_option( 'wcmcf_result' )['id'], 'Last completed feed remains published' );
Builder::batch( $old['id'], 1 );
$assert( ! get_option( 'wcmcf_build' ), 'Superseded worker cannot restore old build' );
Builder::start(); $finish();
$after = $read();
$assert( array_keys( $before ) === array_keys( $after ), 'Renewal preserves all included IDs' );
foreach ( $before as $id => $row ) {
	$assert( $row['image_link'] !== $after[ $id ]['image_link'], 'Every included image URL changes' );
	$assert( str_contains( $after[ $id ]['image_link'], $revision ), 'All batches use the same revision' );
	$row['image_link'] = $after[ $id ]['image_link'];
	$assert( $row === $after[ $id ], 'Only image_link changes, including prices, stock, and IDs' );
}
$assert( Builder::enqueue(), 'Ordinary refresh queues' );
Builder::start(); $finish();
$assert( $after === $read(), 'Ordinary rebuild preserves all renewed image URLs' );
$assert( Builder::enqueue( true ) && $revision !== get_option( 'wcmcf_image_revision' ), 'Next manual renewal uses a new revision' );
Builder::start(); $finish();
$selection['selection_type'] = $selection['type'];
$assert( true === Selection::save( $selection, $brand ), 'Restore saved selection' );
Builder::start(); $finish();
WP_CLI::success( 'Image renewal: complete rebuild, stable subsequent URLs, stale-worker safety, unchanged product data, and revision rotation verified.' );
