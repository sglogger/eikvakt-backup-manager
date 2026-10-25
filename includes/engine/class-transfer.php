<?php
/**
 * Resumable multi-file transfers between the backup directory and the
 * destinations, shared by the backup, restore, retention and migration code.
 *
 * Uploads go to every destination in turn; one failing destination is
 * retried a few times and then reported, without stopping the others — the
 * whole point of several destinations is that one of them being down does
 * not cost the backup.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Transfer {

	private const MAX_ATTEMPTS = 3;

	/**
	 * @param string[] $names        File names, in upload order.
	 * @param string[] $destinations Destination ids.
	 * @return array<string, mixed>
	 */
	public static function start( array $names, array $destinations ): array {
		$targets = [];
		foreach ( $destinations as $id ) {
			$targets[ $id ] = [
				'file'     => 0,
				'state'    => [],
				'status'   => 'pending',
				'error'    => '',
				'attempts' => 0,
			];
		}

		return [
			'files'   => array_values( $names ),
			'targets' => $targets,
		];
	}

	/**
	 * @param array<string, mixed> $state State from start().
	 * @return array<string, mixed>
	 */
	public static function upload_step( array $state, string $local_dir, float $deadline ): array {
		return self::run( $state, $local_dir, $deadline, true );
	}

	/**
	 * Downloads use the same state; each target id is a source here and
	 * normally there is exactly one.
	 *
	 * @param array<string, mixed> $state State from start().
	 * @return array<string, mixed>
	 */
	public static function download_step( array $state, string $local_dir, float $deadline ): array {
		return self::run( $state, $local_dir, $deadline, false );
	}

	/**
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function run( array $state, string $local_dir, float $deadline, bool $upload ): array {
		$files = (array) $state['files'];

		foreach ( $state['targets'] as $id => $target ) {
			if ( 'pending' !== $target['status'] ) {
				continue;
			}

			if ( microtime( true ) >= $deadline ) {
				break;
			}

			try {
				$driver = Destinations::driver( (string) $id );

				$count = count( $files );

				while ( $target['file'] < $count && microtime( true ) < $deadline ) {
					$name  = (string) $files[ $target['file'] ];
					$local = $local_dir . '/' . $name;

					if ( $upload ) {
						$target['state'] = $driver->upload( $local, $name, (array) $target['state'], $deadline );
					} else {
						$partial         = $local . '.part';
						$target['state'] = $driver->download( $name, $partial, (array) $target['state'], $deadline );
						if ( ! empty( $target['state']['done'] ) ) {
							rename( $partial, $local ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- completing a download into the work directory.
						}
					}

					if ( ! empty( $target['state']['done'] ) ) {
						++$target['file'];
						$target['state']    = [];
						$target['attempts'] = 0;
					}
				}

				if ( $target['file'] >= count( $files ) ) {
					$target['status'] = 'done';
				}
			} catch ( \Throwable $e ) {
				++$target['attempts'];
				$target['error'] = Util::message( $e );
				$target['state'] = [];

				if ( $target['attempts'] >= self::MAX_ATTEMPTS ) {
					$target['status'] = 'failed';
				}
			}

			$state['targets'][ $id ] = $target;
		}

		return $state;
	}

	/**
	 * @param array<string, mixed> $state Transfer state.
	 */
	public static function is_finished( array $state ): bool {
		foreach ( (array) $state['targets'] as $target ) {
			if ( 'pending' === $target['status'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Share of work done, 0–1.
	 *
	 * @param array<string, mixed> $state Transfer state.
	 */
	public static function progress( array $state ): float {
		$files   = max( 1, count( (array) $state['files'] ) );
		$targets = max( 1, count( (array) $state['targets'] ) );
		$done    = 0;

		foreach ( (array) $state['targets'] as $target ) {
			$done += 'pending' === $target['status'] ? (int) $target['file'] : $files;
		}

		return min( 1, $done / ( $files * $targets ) );
	}

	/**
	 * @param array<string, mixed> $state Transfer state.
	 * @return array<string, string> Destination id => status.
	 */
	public static function outcome( array $state ): array {
		$out = [];
		foreach ( (array) $state['targets'] as $id => $target ) {
			$out[ (string) $id ] = (string) $target['status'];
		}
		return $out;
	}

	/**
	 * Delete every file of a set from one destination.
	 */
	public static function delete_set( Manifest $manifest, string $destination ): bool {
		$ok     = true;
		$driver = Destinations::driver( $destination );

		// Manifest first: a half-deleted set must not look restorable.
		$names = array_reverse( $manifest->all_file_names() );

		foreach ( $names as $name ) {
			try {
				$ok = $driver->delete( $name ) && $ok;
			} catch ( \Throwable $e ) {
				$ok = false;
			}
		}

		if ( $ok ) {
			Catalog::remove_location( $manifest->id(), $destination );
		}

		return $ok;
	}
}
