<?php
namespace WCMCF;

defined( 'ABSPATH' ) || exit;

/** Each durable batch artifact makes retries safe, even after a worker terminates. */
final class Builder {
	public const GROUP = 'wcmcf';
	public const REFRESH = 'wcmcf_refresh';
	public const BATCH = 'wcmcf_batch';
	public const BATCH_SIZE = 100;
	public const STALE_SECONDS = 900;

	public static function enqueue( bool $renew_images = false ): bool {
		if ( $renew_images ) {
			$lock = false;
			try {
				$lock = Storage::lock( true );
				// Queue while holding the build lock so no worker can start before the revision is saved.
				if ( ! as_enqueue_async_action( self::REFRESH, array(), self::GROUP ) ) { return false; }
				as_unschedule_all_actions( self::BATCH, null, self::GROUP );
				$build = get_option( 'wcmcf_build', array() );
				delete_option( 'wcmcf_build' );
				update_option( 'wcmcf_image_revision', wp_generate_uuid4(), false );
				if ( $build && ( get_option( 'wcmcf_result', array() )['id'] ?? '' ) !== $build['id'] ) { Storage::remove_generation( $build['id'] ); }
				return true;
			} catch ( \Throwable $error ) {
				self::record_failure( $error->getMessage() );
				return false;
			} finally { Storage::unlock( $lock ); }
		}
		// A recurring action already has this hook; uniqueness would suppress manual requests.
		return (bool) as_enqueue_async_action( self::REFRESH, array(), self::GROUP );
	}

	public static function cancel(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::REFRESH, null, self::GROUP );
			as_unschedule_all_actions( self::BATCH, null, self::GROUP );
		}
	}

	public static function start(): void {
		$lock = false;
		try {
			$lock = Storage::lock();
			if ( ! $lock || ! get_option( 'wcmcf_enabled' ) ) {
				return;
			}
			$error = Plugin::configuration_error();
			if ( $error ) {
				throw new \RuntimeException( $error->get_error_message() );
			}
			$old = get_option( 'wcmcf_build', array() );
			$result = get_option( 'wcmcf_result', array() );
			if ( $old && time() - $old['updated'] < self::STALE_SECONDS ) {
				return;
			}
			if ( $old ) {
				as_unschedule_all_actions( self::BATCH, null, self::GROUP );
				if ( ( $result['id'] ?? '' ) !== $old['id'] ) { Storage::remove_generation( $old['id'] ); }
				delete_option( 'wcmcf_build' );
			}
			Storage::cleanup( array_filter( array( $result['id'] ?? '', $result['previous'] ?? '' ) ) );
			$id = wp_generate_uuid4();
			$directory = Storage::generation( $id );
			if ( ! wp_mkdir_p( $directory ) ) {
				throw new \RuntimeException( 'Could not create a feed generation directory.' );
			}
			$state = array( 'id' => $id, 'offset' => 0, 'batch' => 0, 'exported' => 0, 'skipped' => 0, 'omitted' => 0, 'started' => time(), 'updated' => time(), 'brand' => get_option( 'wcmcf_fallback_brand', 'BlissPharma' ), 'selection' => Selection::prepare( Selection::get() ) );
			$state['image_revision'] = (string) get_option( 'wcmcf_image_revision', '' );
			update_option( 'wcmcf_build', $state, false );
			// Snapshot only IDs, never an in-memory catalog of product objects. Stable
			// membership avoids pagination shifts if products are edited during a build.
			$manifest = fopen( $directory . '/manifest.txt', 'wb' );
			if ( false === $manifest ) {
				throw new \RuntimeException( 'Could not write the product ID manifest.' );
			}
			try {
				foreach ( Selection::candidate_ids( $state['selection'] ) as $product_id ) {
					if ( false === fwrite( $manifest, (int) $product_id . "\n" ) ) {
						throw new \RuntimeException( 'Could not save the product ID manifest.' );
					}
				}
			} finally {
				fclose( $manifest );
			}
			update_option( 'wcmcf_status', array( 'state' => 'building', 'error' => '', 'updated' => time() ), false );
			self::queue_batch( $id, 0 );
		} catch ( \Throwable $error ) {
			if ( $lock ) {
				self::fail( $error->getMessage() );
			} else {
				self::record_failure( $error->getMessage() );
			}
		} finally {
			Storage::unlock( $lock );
		}
	}

	public static function batch( string $id, int $index ): void {
		$lock = false;
		try {
			$lock = Storage::lock();
			if ( ! $lock ) {
				// A simultaneous worker must not consume this batch without retrying it.
				as_schedule_single_action( time() + 30, self::BATCH, array( $id, $index ), self::GROUP );
				return;
			}
			$state = get_option( 'wcmcf_build', array() );
			if ( ! get_option( 'wcmcf_enabled' ) || ! $state || $state['id'] !== $id || $state['batch'] !== $index ) {
				return;
			}
			$error = Plugin::configuration_error();
			if ( $error ) {
				throw new \RuntimeException( $error->get_error_message() );
			}
			$directory = Storage::generation( $id );
			$artifact_path = $directory . '/batch-' . $index . '.json';
			if ( file_exists( $artifact_path ) ) {
				$artifact = Storage::read_json( $artifact_path );
			} else {
				$artifact = self::map_batch( $directory, $state );
				Storage::atomic_json( $artifact_path, $artifact );
			}
			$state['offset'] = $artifact['offset'];
			$state['batch']++;
			$state['exported'] += count( $artifact['rows'] );
			$state['skipped'] += count( $artifact['issues'] );
			$state['omitted'] += $artifact['omitted'];
			$state['updated'] = time();
			update_option( 'wcmcf_build', $state, false );
			if ( $artifact['done'] ) {
				self::publish( $directory, $state );
			} else {
				self::queue_batch( $id, $state['batch'] );
			}
		} catch ( \Throwable $error ) {
			if ( $lock ) {
				self::fail( $error->getMessage() );
			} else {
				self::record_failure( $error->getMessage() );
			}
		} finally {
			Storage::unlock( $lock );
		}
	}

	private static function queue_batch( string $id, int $index ): void {
		$args = array( $id, $index );
		if ( as_has_scheduled_action( self::BATCH, $args, self::GROUP ) ) {
			return;
		}
		// Action Scheduler's unique flag ignores arguments and would reject the
		// next batch while the current batch is running. Our file lock serializes
		// scheduling; the explicit argument check prevents duplicate batch jobs.
		if ( ! as_enqueue_async_action( self::BATCH, $args, self::GROUP ) ) {
			throw new \RuntimeException( 'Could not schedule the next feed batch. Check WooCommerce Scheduled Actions.' );
		}
	}

	private static function map_batch( string $directory, array $state ): array {
		$manifest = fopen( $directory . '/manifest.txt', 'rb' );
		if ( false === $manifest ) {
			throw new \RuntimeException( 'The product ID manifest is missing.' );
		}
		try {
			if ( 0 !== fseek( $manifest, $state['offset'] ) ) {
				throw new \RuntimeException( 'Could not read product ID manifest progress.' );
			}
			$mapper = new Mapper( $state['brand'], $state['image_revision'] ?? '' );
			$artifact = array( 'rows' => array(), 'issues' => array(), 'omitted' => 0 );
			for ( $count = 0; $count < self::BATCH_SIZE; $count++ ) {
				$line = fgets( $manifest );
				if ( false === $line ) {
					break;
				}
				$id = (int) trim( $line );
				$product = wc_get_product( $id );
				$row = $product && ( ! isset( $state['selection'] ) || Selection::matches( $product, $state['selection'] ) ) ? $mapper->row( $product ) : null;
				if ( is_wp_error( $row ) ) {
					$artifact['issues'][] = array( 'id' => $id, 'reason' => $row->get_error_message() );
				} elseif ( null !== $row ) {
					$artifact['rows'][] = $row;
				} else {
					$artifact['omitted']++;
				}
			}
			$artifact['offset'] = ftell( $manifest );
			$artifact['done'] = $artifact['offset'] >= filesize( $directory . '/manifest.txt' );
			return $artifact;
		} finally {
			fclose( $manifest );
		}
	}

	private static function publish( string $directory, array $state ): void {
		$csv = fopen( $directory . '/catalog.csv.part', 'wb' );
		$issues = fopen( $directory . '/skipped.jsonl.part', 'wb' );
		if ( false === $csv || false === $issues ) {
			if ( is_resource( $csv ) ) { fclose( $csv ); }
			if ( is_resource( $issues ) ) { fclose( $issues ); }
			throw new \RuntimeException( 'Could not create the completed feed files.' );
		}
		try {
			self::write_csv( $csv, Mapper::COLUMNS );
			for ( $index = 0; $index < $state['batch']; $index++ ) {
				$artifact = Storage::read_json( $directory . '/batch-' . $index . '.json' );
				foreach ( $artifact['rows'] as $row ) {
					self::write_csv( $csv, $row );
				}
				foreach ( $artifact['issues'] as $issue ) {
					if ( false === fwrite( $issues, wp_json_encode( $issue, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n" ) ) {
						throw new \RuntimeException( 'Could not write the skipped-product report.' );
					}
				}
			}
			if ( ! fflush( $csv ) || ! fflush( $issues ) ) {
				throw new \RuntimeException( 'Could not flush the completed feed files.' );
			}
		} finally {
			fclose( $csv );
			fclose( $issues );
		}
		if ( ! rename( $directory . '/catalog.csv.part', $directory . '/catalog.csv' ) || ! rename( $directory . '/skipped.jsonl.part', $directory . '/skipped.jsonl' ) ) {
			throw new \RuntimeException( 'Could not publish the completed feed files.' );
		}
		$error = Plugin::configuration_error();
		if ( $error ) {
			throw new \RuntimeException( $error->get_error_message() );
		}
		$previous = get_option( 'wcmcf_result', array() );
		$result = array( 'id' => $state['id'], 'previous' => $previous['id'] ?? '', 'completed' => time(), 'exported' => $state['exported'], 'skipped' => $state['skipped'], 'omitted' => $state['omitted'], 'selection_type' => $state['selection']['type'] ?? 'all' );
		// One atomic pointer publishes the CSV and its report together. Downloads can
		// continue reading the previous generation throughout this entire build.
		if ( ! update_option( 'wcmcf_result', $result, false ) ) {
			throw new \RuntimeException( 'Could not save the completed feed reference.' );
		}
		delete_option( 'wcmcf_build' );
		update_option( 'wcmcf_status', array( 'state' => 'ready', 'error' => '', 'updated' => time() ), false );
		// Keep the previous generation for requests that read its reference before publication.
		Storage::cleanup( array_filter( array( $result['id'], $result['previous'] ) ) );
		foreach ( glob( $directory . '/batch-*.json' ) ?: array() as $path ) { wp_delete_file( $path ); }
		wp_delete_file( $directory . '/manifest.txt' );
	}

	/** Empty escape character uses standard doubled quotes, including literal backslashes. */
	public static function write_csv( $stream, array $row ): void {
		if ( false === fputcsv( $stream, $row, ',', '"', '', "\r\n" ) ) {
			throw new \RuntimeException( 'Could not write a catalog CSV row.' );
		}
	}

	private static function record_failure( string $message ): void {
		update_option( 'wcmcf_status', array( 'state' => 'failed', 'error' => $message, 'updated' => time() ), false );
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'wcmcf' ) );
		}
	}

	private static function fail( string $message ): void {
		$state = get_option( 'wcmcf_build', array() );
		delete_option( 'wcmcf_build' );
		if ( $state && ( get_option( 'wcmcf_result', array() )['id'] ?? '' ) !== $state['id'] ) {
			try { Storage::remove_generation( $state['id'] ); } catch ( \Throwable $ignored ) { /* Report the original failure. */ }
		}
		self::record_failure( $message );
	}
}
