<?php
/**
 * Small helpers shared by the engine, the storage drivers and the admin.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Util {

	/**
	 * Hex token from a CSPRNG. Used for backup ids, job tokens and directory
	 * names — anything that must not be guessable.
	 */
	public static function random_token( int $bytes = 16 ): string {
		return bin2hex( random_bytes( max( 4, $bytes ) ) );
	}

	/**
	 * Recursively delete a directory. Refuses obviously dangerous targets so
	 * a misconfigured path can never take the site with it.
	 */
	public static function delete_tree( string $dir ): bool {
		$dir = rtrim( $dir, '/\\' );

		if ( '' === $dir || ! is_dir( $dir ) || is_link( $dir ) ) {
			return false;
		}

		$real = realpath( $dir );
		if ( false === $real ) {
			return false;
		}

		$forbidden = array_filter(
			[
				realpath( ABSPATH ),
				defined( 'WP_CONTENT_DIR' ) ? realpath( WP_CONTENT_DIR ) : false,
				realpath( '/' ),
				realpath( dirname( ABSPATH ) ),
			]
		);

		if ( in_array( $real, $forbidden, true ) ) {
			return false;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $real, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem is unavailable in cron and the CLI, where cleanup also runs; a file vanishing concurrently is not an error.
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}

		return @rmdir( $real );
		// phpcs:enable
	}

	/**
	 * Human-readable byte count.
	 */
	public static function bytes( int|float $bytes ): string {
		$formatted = size_format( (int) $bytes, $bytes >= 1048576 ? 1 : 0 );
		return false === $formatted ? '0 B' : (string) $formatted;
	}

	/**
	 * Local date/time in the site's format and timezone.
	 */
	public static function datetime( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '—';
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ?: '—';
	}

	/**
	 * Raise the PHP time limit where the host allows it. The engine never
	 * relies on this — every step honours its own deadline — but a higher
	 * limit gives a single slow write (closing a large ZIP) room to finish.
	 */
	public static function raise_limits(): void {
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- long-running backup work; ignored where the host forbids it.
			@set_time_limit( 300 );
		}

		wp_raise_memory_limit( 'admin' );
	}

	/**
	 * A filename that is safe to use as a remote object name: the catalog only
	 * ever produces names in this shape, so anything else is rejected rather
	 * than sanitised.
	 */
	public static function is_safe_filename( string $name ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9._-]{0,180}$/', $name ) && ! str_contains( $name, '..' );
	}

	/**
	 * Running in WordPress Playground (PHP compiled to WebAssembly)?
	 *
	 * Playground's HTTP bridge in the browser cannot send binary request
	 * bodies (any non-text byte makes the request fail with "Missing
	 * header/body separator"), and there are no FTP/SSH sockets. Backup
	 * archives are binary, so remote destinations cannot work there.
	 */
	public static function is_playground(): bool {
		return function_exists( 'post_message_to_js' ) || 'Emscripten' === php_uname( 's' );
	}

	public static function playground_notice(): string {
		return __( 'This site runs in WordPress Playground. Its browser sandbox cannot upload binary files such as backup archives, so remote destinations do not work here; backups stay on this server. On a real server, Dropbox, OneDrive, FTP and SFTP work normally.', 'eikvakt-backup-manager' );
	}

	/**
	 * Message of an exception as plain text. Messages are HTML-escaped when
	 * thrown (coding standards) and escaped again wherever they are shown, so
	 * they are decoded once here before being stored or reported.
	 */
	public static function message( \Throwable $e ): string {
		return wp_specialchars_decode( $e->getMessage(), ENT_QUOTES );
	}

	/**
	 * Copy a stream to another in bounded chunks.
	 *
	 * @param resource $from Source.
	 * @param resource $to   Destination.
	 */
	public static function pipe( $from, $to, int $limit = -1 ): int {
		$copied = 0;
		while ( ! feof( $from ) && ( $limit < 0 || $copied < $limit ) ) {
			$want  = $limit < 0 ? 1048576 : min( 1048576, $limit - $copied );
			$chunk = fread( $from, $want ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streaming copy of a backup archive.
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			fwrite( $to, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming copy of a backup archive.
			$copied += strlen( $chunk );
		}
		return $copied;
	}
}
