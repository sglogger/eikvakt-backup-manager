<?php
/**
 * Applies the retention policy to the catalog and every destination.
 *
 * Runs at the end of each backup. Deletions are per location: a copy that
 * cannot be deleted (destination offline) stays in the catalog and is tried
 * again after the next backup.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Retention {

	/**
	 * @return int Number of sets removed completely.
	 */
	public static function apply(): int {
		$rules = Settings::get( Installer::OPTION_RETENTION );
		$key   = (string) get_option( Installer::OPTION_STORAGE_KEY, '' );
		$sets  = array_values(
			array_filter(
				Catalog::all(),
				static fn( Manifest $m ) => $m->is_complete() && $m->site( 'key' ) === $key
			)
		);

		/**
		 * Filters the backup ids retention is about to delete.
		 *
		 * @param string[]   $ids  Ids.
		 * @param Manifest[] $sets All complete sets.
		 */
		$ids = (array) apply_filters( 'ekbm_retention_delete', Retention_Policy::to_delete( $sets, $rules, time(), wp_timezone() ), $sets );

		$removed = 0;
		foreach ( $ids as $id ) {
			if ( self::delete( (string) $id ) ) {
				++$removed;
			}
		}

		// Local-only limits: drop copies on this server that also live elsewhere.
		$local     = [];
		$locations = [];
		foreach ( $sets as $set ) {
			$locations[ $set->id() ] = Catalog::locations( $set->id() );
			if ( in_array( Storage_Local::ID, $locations[ $set->id() ], true ) ) {
				$local[] = $set;
			}
		}

		// Per-location age limits: local days, remote days, or a destination's
		// own override.
		$days = [ Storage_Local::ID => max( 0, (int) ( $rules['local_days'] ?? 0 ) ) ];
		foreach ( Destinations::all() as $id => $destination ) {
			$override    = (int) ( $destination['retention_days'] ?? 0 );
			$days[ $id ] = $override > 0 ? $override : max( 0, (int) ( $rules['remote_days'] ?? 0 ) );
		}

		foreach ( Retention_Policy::expired_copies( $sets, $locations, $days, time() ) as [ $set_id, $location ] ) {
			$manifest = Catalog::get( $set_id );
			if ( null === $manifest ) {
				continue;
			}
			try {
				Transfer::delete_set( $manifest, $location );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( null === Catalog::get( $set_id ) ) {
				++$removed;
			}
		}

		foreach ( Retention_Policy::local_to_delete( $local, $locations, $rules ) as $id ) {
			$manifest = Catalog::get( $id );
			if ( null !== $manifest ) {
				Transfer::delete_set( $manifest, Storage_Local::ID );
			}
		}

		return $removed;
	}

	/**
	 * Delete one set everywhere it is stored.
	 */
	public static function delete( string $id ): bool {
		$manifest = Catalog::get( $id );
		if ( null === $manifest ) {
			return false;
		}

		$ok = true;
		foreach ( Catalog::locations( $id ) as $location ) {
			try {
				$ok = Transfer::delete_set( $manifest, $location ) && $ok;
			} catch ( \Throwable $e ) {
				$ok = false;
			}
		}

		wp_delete_file( Backup_Job::index_cache_path( $id ) );

		return $ok;
	}
}
