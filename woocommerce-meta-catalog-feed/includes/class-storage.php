<?php
namespace WCMCF;

defined( 'ABSPATH' ) || exit;

/** Files are private working artifacts; only the completed CSV endpoint is public. */
final class Storage {
	public static function root(): string {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			throw new \RuntimeException( 'The WordPress uploads directory is unavailable.' );
		}
		return $uploads['basedir'] . '/wcmcf-feed';
	}

	public static function prepare(): void {
		$root = self::root();
		if ( ! wp_mkdir_p( $root ) || ! is_writable( $root ) ) {
			throw new \RuntimeException( 'The feed directory is not writable. Check uploads directory permissions.' );
		}
		foreach ( array( 'index.php' => "<?php http_response_code(404); exit;\n", '.htaccess' => "Require all denied\n", 'web.config' => '<?xml version="1.0"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>' ) as $name => $contents ) {
			if ( ! file_exists( $root . '/' . $name ) && false === file_put_contents( $root . '/' . $name, $contents ) ) {
				throw new \RuntimeException( 'Could not initialize the feed directory.' );
			}
		}
	}

	/** Serializes builders and deactivation, including workers using persistent object caches. */
	public static function lock( bool $wait = false ) {
		self::prepare();
		$handle = fopen( self::root() . '/worker.lock', 'c' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not open the feed build lock.' );
		}
		if ( ! flock( $handle, LOCK_EX | ( $wait ? 0 : LOCK_NB ) ) ) {
			fclose( $handle );
			return false;
		}
		// Refresh state cached by this request before it waited for another process.
		foreach ( array( 'wcmcf_build', 'wcmcf_result', 'wcmcf_status', 'wcmcf_enabled', 'wcmcf_selection', 'wcmcf_fallback_brand' ) as $option ) {
			wp_cache_delete( $option, 'options' );
		}
		wp_cache_delete( 'notoptions', 'options' );
		return $handle;
	}

	public static function unlock( $handle ): void {
		if ( is_resource( $handle ) ) {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	public static function generation( string $id ): string {
		if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $id ) ) {
			throw new \RuntimeException( 'Invalid feed generation ID.' );
		}
		return self::root() . '/build-' . $id;
	}

	public static function atomic_json( string $path, array $data ): void {
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		if ( strlen( $json ) !== @file_put_contents( $path . '.part', $json, LOCK_EX ) || ! @rename( $path . '.part', $path ) ) {
			throw new \RuntimeException( 'Could not save feed build progress.' );
		}
	}

	public static function read_json( string $path ): array {
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			throw new \RuntimeException( 'A feed build artifact is missing.' );
		}
		return json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
	}

	/** Only removes files in plugin-owned generation directories, never follows symlinks. */
	public static function remove_generation( string $id ): void {
		$directory = self::generation( $id );
		if ( ! is_dir( $directory ) || is_link( $directory ) ) {
			return;
		}
		foreach ( new \DirectoryIterator( $directory ) as $file ) {
			if ( ! $file->isDot() && ( $file->isFile() || $file->isLink() ) ) {
				wp_delete_file( $file->getPathname() );
			}
		}
		@rmdir( $directory );
	}

	public static function cleanup( array $keep ): void {
		foreach ( glob( self::root() . '/build-*', GLOB_ONLYDIR ) ?: array() as $directory ) {
			$id = substr( basename( $directory ), 6 );
			if ( preg_match( '/^[a-f0-9-]{36}$/D', $id ) && ! in_array( $id, $keep, true ) ) {
				self::remove_generation( $id );
			}
		}
	}
}
