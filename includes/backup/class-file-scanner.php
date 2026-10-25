<?php
/**
 * Walks the installation and lists the files a backup should contain.
 *
 * Resumable: the queue of directories still to read is part of the job
 * state, so a site with hundreds of thousands of files is scanned over as
 * many requests as it takes, never in one that the host kills half-way.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class File_Scanner {

	/**
	 * Absolute directory of a logical root.
	 */
	public static function root_dir( string $root ): string {
		return 'content' === $root
			? untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) )
			: untrailingslashit( wp_normalize_path( ABSPATH ) );
	}

	/**
	 * Absolute path of an archive path on this site.
	 */
	public static function real_path( string $archive_path ): string {
		if ( str_starts_with( $archive_path, 'content/' ) ) {
			return self::root_dir( 'content' ) . '/' . substr( $archive_path, 8 );
		}
		if ( str_starts_with( $archive_path, 'root/' ) ) {
			return self::root_dir( 'root' ) . '/' . substr( $archive_path, 5 );
		}
		return '';
	}

	/**
	 * Initial scan state for the given components.
	 *
	 * @param string[] $components File components to include.
	 * @param string[] $only       Optional archive paths (folders or single
	 *                             files) to limit the scan to, e.g.
	 *                             `content/plugins/akismet` before updating
	 *                             one plugin.
	 * @return array<string, mixed>
	 */
	public static function start( array $components, string $list_file, array $only = [] ): array {
		$queue = [];
		$lines = '';

		if ( [] !== $only ) {
			foreach ( $only as $path ) {
				$path = trim( (string) $path, '/' );
				if ( ! preg_match( '#^(root|content)/(.+)$#', $path, $m ) || str_contains( $path, '..' ) ) {
					continue;
				}
				$real = self::real_path( $path );
				if ( is_dir( $real ) ) {
					$queue[] = [ $m[1], $m[2] ];
				} elseif ( is_file( $real ) && is_readable( $real ) ) {
					$lines .= File_Index::format_line( $path, (int) filesize( $real ), (int) filemtime( $real ) );
				}
			}
		} else {
			if ( in_array( Components::CORE, $components, true ) ) {
				$queue[] = [ 'root', '' ];
			}

			if ( array_intersect( [ Components::PLUGINS, Components::THEMES, Components::UPLOADS, Components::CONTENT ], $components ) ) {
				$queue[] = [ 'content', '' ];
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- truncate the scan list in the protected work directory.
		file_put_contents( $list_file, $lines );

		return [
			'queue'      => $queue,
			'components' => array_values( $components ),
			'list'       => $list_file,
			'files'      => substr_count( $lines, "\n" ),
			'bytes'      => 0,
			'skipped'    => [],
			'done'       => false,
		];
	}

	/**
	 * Read directories until the deadline.
	 *
	 * @param array<string, mixed> $state Scan state from start() or a previous step.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		$rules      = Settings::file_rules();
		$max_bytes  = (int) ( Settings::general()['exclude_larger_than_mb'] ?? 0 ) * 1048576;
		$components = (array) $state['components'];
		$skip_dirs  = self::skip_dirs();

		$content_dir = self::root_dir( 'content' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- appending to the scan list in the protected work directory.
		$out = fopen( (string) $state['list'], 'ab' );
		if ( false === $out ) {
			$state['error'] = 'scan-list-not-writable';
			return $state;
		}

		while ( ! empty( $state['queue'] ) && microtime( true ) < $deadline ) {
			[ $root, $rel ] = array_shift( $state['queue'] );

			$dir = self::root_dir( $root ) . ( '' !== $rel ? '/' . $rel : '' );

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable directory is recorded below, not fatal.
			$handle = @opendir( $dir );
			if ( false === $handle ) {
				$state['skipped'][] = $root . '/' . $rel;
				continue;
			}

			// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- directory reader.
			while ( false !== ( $name = readdir( $handle ) ) ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}

				$path         = $dir . '/' . $name;
				$child_rel    = '' !== $rel ? $rel . '/' . $name : $name;
				$archive_path = $root . '/' . $child_rel;

				if ( str_contains( $name, "\t" ) || str_contains( $name, "\n" ) || str_contains( $name, "\r" ) ) {
					$state['skipped'][] = $archive_path;
					continue;
				}

				if ( is_dir( $path ) ) {
					if ( is_link( $path ) ) {
						continue;
					}

					$normalised = wp_normalize_path( $path );

					// wp-content is scanned under its own root, never twice.
					if ( 'root' === $root && $normalised === $content_dir ) {
						continue;
					}
					if ( isset( $skip_dirs[ $normalised ] ) ) {
						continue;
					}
					// An excluded folder is still entered when a folder below it
					// is explicitly included; its own files stay excluded.
					if ( ! $rules->descend( $archive_path ) ) {
						continue;
					}
					if ( 'content' === $root && '' === $rel && ! in_array( Components::classify( $archive_path . '/' ), $components, true ) ) {
						continue;
					}

					$state['queue'][] = [ $root, $child_rel ];
					continue;
				}

				if ( ! in_array( Components::classify( $archive_path ), $components, true ) ) {
					continue;
				}
				if ( $rules->excluded_file( $archive_path, 0 ) || str_starts_with( $name, Paths::PROBE_PREFIX ) ) {
					continue;
				}

				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file vanishing mid-scan is ordinary.
				$stat = @stat( $path );
				if ( false === $stat || ! is_readable( $path ) ) {
					$state['skipped'][] = $archive_path;
					continue;
				}

				$size = (int) $stat['size'];
				if ( $max_bytes > 0 && $size > $max_bytes ) {
					$state['skipped'][] = $archive_path;
					continue;
				}

				fwrite( $out, File_Index::format_line( $archive_path, $size, (int) $stat['mtime'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- scan list.
				++$state['files'];
				$state['bytes'] += $size;
			}

			closedir( $handle );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- scan list.

		// Only a bounded sample of skipped paths is kept in the job state.
		$state['skipped'] = array_slice( (array) $state['skipped'], 0, 200 );
		$state['done']    = empty( $state['queue'] );

		return $state;
	}

	/**
	 * Directories that are never part of a backup: the backup directory
	 * itself (a backup containing every previous backup grows without bound)
	 * and its work area.
	 *
	 * @return array<string, true>
	 */
	public static function skip_dirs(): array {
		$dirs = [ wp_normalize_path( Paths::backup_dir() ) => true ];

		$real = realpath( Paths::backup_dir() );
		if ( false !== $real ) {
			$dirs[ wp_normalize_path( $real ) ] = true;
		}

		return $dirs;
	}
}
