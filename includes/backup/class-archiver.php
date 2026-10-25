<?php
/**
 * Packs the listed files into ZIP parts.
 *
 * ZipArchive compresses when the archive is closed, not when a file is added,
 * so each step adds a bounded batch (by bytes and by time) and closes the
 * archive before handing over. Parts are rotated at the configured size: one
 * multi-gigabyte ZIP is hard to upload, hard to download and fails as a unit.
 *
 * Already-compressed formats are stored rather than deflated — re-compressing
 * a JPEG costs CPU and saves nothing.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Archiver {

	/** Upper bound of uncompressed input added between two close() calls. */
	private const BATCH_BYTES = 64 * 1048576;

	private const STORE_EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'mp3', 'mp4', 'm4a', 'mov', 'webm', 'ogg', 'woff', 'woff2', 'pdf' ];

	public static function is_available(): bool {
		return class_exists( '\ZipArchive' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function start( string $list_file, string $dir, string $base, int $part_limit ): array {
		return [
			'list'       => $list_file,
			'cursor'     => 0,
			'dir'        => $dir,
			'base'       => $base,
			'part'       => 0,
			'part_bytes' => 0,
			'part_limit' => max( 10 * 1048576, $part_limit ),
			'parts'      => [],
			'added'      => 0,
			'bytes'      => 0,
			'skipped'    => [],
			'done'       => false,
		];
	}

	public static function part_name( string $base, int $part ): string {
		return sprintf( '%s.files-%03d.zip', $base, $part );
	}

	/**
	 * @param array<string, mixed> $state State from start() or a previous step.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- change list in the protected work directory.
		$list = fopen( (string) $state['list'], 'rb' );
		if ( false === $list ) {
			$state['error'] = 'change-list-missing';
			return $state;
		}

		fseek( $list, (int) $state['cursor'] );

		if ( 0 === (int) $state['part'] ) {
			$state['part'] = 1;
		}

		$zip      = null;
		$batch    = 0;
		$zip_path = '';

		while ( microtime( true ) < $deadline && $batch < self::BATCH_BYTES ) {
			$position = ftell( $list );
			$line     = fgets( $list );

			if ( false === $line ) {
				$state['done'] = true;
				break;
			}

			$entry = File_Index::parse_line( $line );
			if ( null === $entry ) {
				$state['cursor'] = ftell( $list );
				continue;
			}

			[ $archive_path, $size ] = $entry;

			// Rotate before a file that would push the part over its limit,
			// unless the part is still empty (a single huge file gets a part
			// of its own).
			if ( (int) $state['part_bytes'] > 0 && (int) $state['part_bytes'] + $size > (int) $state['part_limit'] ) {
				if ( null !== $zip ) {
					$zip->close();
					$zip = null;
					self::register_part( $state, $zip_path );
				}
				++$state['part'];
				$state['part_bytes'] = 0;

				// Close-and-continue happens in the next request, so the part
				// just finished is accounted for before more work starts.
				fseek( $list, (int) $position );
				$state['cursor'] = $position;
				break;
			}

			if ( null === $zip ) {
				$zip_path = $state['dir'] . '/' . self::part_name( (string) $state['base'], (int) $state['part'] );
				$zip      = new \ZipArchive();
				if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE ) ) {
					$state['error'] = 'zip-open-failed';
					fclose( $list ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- change list.
					return $state;
				}
			}

			$real = File_Scanner::real_path( $archive_path );

			if ( '' === $real || ! is_readable( $real ) || ! $zip->addFile( $real, $archive_path ) ) {
				$state['skipped'][] = $archive_path;
			} else {
				$extension = strtolower( pathinfo( $archive_path, PATHINFO_EXTENSION ) );
				if ( in_array( $extension, self::STORE_EXTENSIONS, true ) ) {
					$zip->setCompressionName( $archive_path, \ZipArchive::CM_STORE );
				}

				++$state['added'];
				$state['bytes']      += $size;
				$state['part_bytes'] += $size;
				$batch               += $size;
			}

			$state['cursor'] = ftell( $list );
		}

		fclose( $list ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- change list.

		if ( null !== $zip ) {
			if ( ! $zip->close() ) {
				$state['error'] = 'zip-close-failed';
				return $state;
			}
			self::register_part( $state, $zip_path );
		}

		$state['skipped'] = array_slice( (array) $state['skipped'], 0, 200 );

		return $state;
	}

	/**
	 * @param array<string, mixed> $state Archiver state.
	 */
	private static function register_part( array &$state, string $zip_path ): void {
		$name = basename( $zip_path );
		if ( is_file( $zip_path ) && ! in_array( $name, (array) $state['parts'], true ) ) {
			$state['parts'][] = $name;
		}
	}
}
