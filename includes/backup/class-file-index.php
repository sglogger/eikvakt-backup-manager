<?php
/**
 * The file index: every file a backup set represents, and which set in the
 * chain holds its content.
 *
 * One gzip text line per file: `<archive path> TAB <size> TAB <mtime> TAB
 * <holder id>`. An incremental backup writes a complete index — unchanged
 * files keep the holder of the set that last stored them, changed and new
 * files name the incremental itself, deleted files are simply absent. That
 * single property makes both directions cheap:
 *
 *   - the next incremental compares the scan against one index instead of
 *     replaying the whole chain;
 *   - a restore reads one index and knows exactly which archive to open for
 *     each file, and which files did not exist at that point in time.
 *
 * Free of WordPress: unit-tested on its own.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class File_Index {

	public const HEADER = '#ekbm-index 1';

	/**
	 * @return array{0:string, 1:int, 2:int, 3:string}|null Path, size, mtime, holder.
	 */
	public static function parse_line( string $line ): ?array {
		$line = rtrim( $line, "\r\n" );
		if ( '' === $line || '#' === $line[0] ) {
			return null;
		}

		$parts = explode( "\t", $line );
		if ( count( $parts ) < 3 ) {
			return null;
		}

		return [ $parts[0], (int) $parts[1], (int) $parts[2], (string) ( $parts[3] ?? '' ) ];
	}

	public static function format_line( string $path, int $size, int $mtime, string $holder = '' ): string {
		return $path . "\t" . $size . "\t" . $mtime . ( '' !== $holder ? "\t" . $holder : '' ) . "\n";
	}

	/**
	 * Load an index into memory: path => [size, mtime, holder].
	 *
	 * Roughly 150 bytes per file, so a site with 100 000 files needs about
	 * 15 MB — acceptable for the one place it is needed at once.
	 *
	 * @return array<string, array{0:int, 1:int, 2:string}>
	 */
	public static function load( string $gz_path ): array {
		$index = [];

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing index is answered by the empty return.
		$handle = @gzopen( $gz_path, 'rb' );
		if ( false === $handle ) {
			return $index;
		}

		while ( ! gzeof( $handle ) ) {
			$line = gzgets( $handle );
			if ( false === $line ) {
				break;
			}
			$entry = self::parse_line( $line );
			if ( null !== $entry ) {
				$index[ $entry[0] ] = [ $entry[1], $entry[2], $entry[3] ];
			}
		}

		gzclose( $handle );

		return $index;
	}

	/**
	 * Compare a fresh scan list against the previous index, writing the new
	 * index and the list of files that need archiving.
	 *
	 * @param string                                       $scan_list  Plain text, `path TAB size TAB mtime` per line.
	 * @param array<string, array{0:int, 1:int, 2:string}> $previous   Previous index ([] for a full backup).
	 * @param string                                       $holder     Id of the set being written.
	 * @param string                                       $index_out  Gzip index to write.
	 * @param string                                       $change_out Plain list of files to archive.
	 * @return array{files:int, changed:int, deleted:int, bytes:int}
	 */
	public static function diff( string $scan_list, array $previous, string $holder, string $index_out, string $change_out ): array {
		$stats = [
			'files'   => 0,
			'changed' => 0,
			'deleted' => 0,
			'bytes'   => 0,
		];

		// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming large text files in the protected work directory.
		$in      = fopen( $scan_list, 'rb' );
		$changes = fopen( $change_out, 'wb' );
		$index   = gzopen( $index_out, 'wb6' );

		if ( false === $in || false === $changes || false === $index ) {
			return $stats;
		}

		gzwrite( $index, self::HEADER . "\n" );

		$seen = [];
		while ( false !== ( $line = fgets( $in ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- line reader.
			$entry = self::parse_line( $line );
			if ( null === $entry ) {
				continue;
			}

			[ $path, $size, $mtime ] = $entry;
			++$stats['files'];
			$seen[ $path ] = true;

			$old = $previous[ $path ] ?? null;

			if ( null !== $old && $old[0] === $size && $old[1] === $mtime && '' !== $old[2] ) {
				gzwrite( $index, self::format_line( $path, $size, $mtime, $old[2] ) );
				continue;
			}

			gzwrite( $index, self::format_line( $path, $size, $mtime, $holder ) );
			fwrite( $changes, self::format_line( $path, $size, $mtime ) );
			++$stats['changed'];
			$stats['bytes'] += $size;
		}

		foreach ( $previous as $path => $unused ) {
			if ( ! isset( $seen[ $path ] ) ) {
				++$stats['deleted'];
			}
		}

		fclose( $in );
		fclose( $changes );
		gzclose( $index );
		// phpcs:enable

		return $stats;
	}
}
