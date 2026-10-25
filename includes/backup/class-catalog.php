<?php
/**
 * The list of known backup sets and where copies of each are stored.
 *
 * Kept as a JSON file inside the protected backup directory rather than in
 * the database on purpose: restoring last month's database must not make
 * WordPress forget about every backup taken since. The catalog can always be
 * rebuilt from the manifests on disk and on each destination ("Rescan").
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Catalog {

	private const FILE = 'catalog.json';

	/** @var array<string, array{manifest: array<string, mixed>, locations: string[]}>|null */
	private static ?array $cache = null;

	/**
	 * @return array<string, array{manifest: array<string, mixed>, locations: string[]}>
	 */
	private static function load(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$path = Paths::backup_dir() . '/' . self::FILE;
		$data = [];

		if ( is_readable( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file inside the protected backup directory.
			$decoded = json_decode( (string) file_get_contents( $path ), true );
			$data    = is_array( $decoded ) ? $decoded : [];
		}

		self::$cache = $data;
		return $data;
	}

	/**
	 * @param array<string, array{manifest: array<string, mixed>, locations: string[]}> $data Catalog.
	 */
	private static function store( array $data ): void {
		Paths::ensure();

		uasort( $data, static fn( $a, $b ) => (int) ( $b['manifest']['created'] ?? 0 ) <=> (int) ( $a['manifest']['created'] ?? 0 ) );

		self::$cache = $data;

		$path = Paths::backup_dir() . '/' . self::FILE;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local file inside the protected backup directory; WP_Filesystem is unavailable in cron.
		file_put_contents( $path, (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES ), LOCK_EX );
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * All sets, newest first.
	 *
	 * @return Manifest[]
	 */
	public static function all(): array {
		$out = [];
		foreach ( self::load() as $entry ) {
			$out[] = self::manifest_of( $entry );
		}
		return $out;
	}

	public static function get( string $id ): ?Manifest {
		$data = self::load();
		return isset( $data[ $id ] ) ? self::manifest_of( $data[ $id ] ) : null;
	}

	/**
	 * @param array<string, mixed> $entry Catalog entry.
	 */
	private static function manifest_of( array $entry ): Manifest {
		$manifest               = new Manifest( (array) $entry['manifest'] );
		$manifest->data['keep'] = ! empty( $entry['keep'] );
		return $manifest;
	}

	/**
	 * Mark or unmark a set as "keep": excluded from every automatic deletion.
	 *
	 * Stored in the catalog only. The manifest files on the destinations stay
	 * untouched, so marking a set never re-uploads anything — and a catalog
	 * rebuilt from scratch ("Rescan" after losing catalog.json) starts with no
	 * marks.
	 */
	public static function set_keep( string $id, bool $keep ): bool {
		$data = self::load();
		if ( ! isset( $data[ $id ] ) ) {
			return false;
		}
		$data[ $id ]['keep'] = $keep;
		self::store( $data );
		return true;
	}

	/**
	 * Ids of the set and every set that would have to go with it or that it
	 * depends on, for "is anything here marked keep?" checks.
	 *
	 * @param string[] $ids Set ids.
	 * @return string[]
	 */
	public static function kept_among( array $ids ): array {
		$kept = [];
		foreach ( $ids as $id ) {
			$manifest = self::get( (string) $id );
			if ( null !== $manifest && $manifest->is_kept() ) {
				$kept[] = (string) $id;
			}
		}
		return $kept;
	}

	/**
	 * @return string[] Destination ids holding a complete copy.
	 */
	public static function locations( string $id ): array {
		$data = self::load();
		return array_values( array_map( 'strval', (array) ( $data[ $id ]['locations'] ?? [] ) ) );
	}

	/**
	 * @param string[] $locations Destination ids.
	 */
	public static function put( Manifest $manifest, array $locations ): void {
		$data = self::load();

		$existing = (array) ( $data[ $manifest->id() ]['locations'] ?? [] );
		$raw      = $manifest->data;
		unset( $raw['keep'] );

		$data[ $manifest->id() ] = [
			'manifest'  => $raw,
			'locations' => array_values( array_unique( array_merge( $existing, $locations ) ) ),
			'keep'      => ! empty( $data[ $manifest->id() ]['keep'] ),
		];

		self::store( $data );
	}

	public static function add_location( string $id, string $destination ): void {
		$data = self::load();
		if ( ! isset( $data[ $id ] ) ) {
			return;
		}
		$data[ $id ]['locations'][] = $destination;
		$data[ $id ]['locations']   = array_values( array_unique( $data[ $id ]['locations'] ) );
		self::store( $data );
	}

	/**
	 * Forget one copy. A set with no copies left is dropped from the catalog.
	 */
	public static function remove_location( string $id, string $destination ): void {
		$data = self::load();
		if ( ! isset( $data[ $id ] ) ) {
			return;
		}

		$data[ $id ]['locations'] = array_values( array_diff( (array) $data[ $id ]['locations'], [ $destination ] ) );

		if ( empty( $data[ $id ]['locations'] ) ) {
			unset( $data[ $id ] );
		}

		self::store( $data );
	}

	public static function remove( string $id ): void {
		$data = self::load();
		unset( $data[ $id ] );
		self::store( $data );
	}

	/**
	 * The chain a set depends on, oldest (the full backup) first and the set
	 * itself last. Empty when an ancestor is missing — such a set cannot be
	 * restored completely and the caller must say so.
	 *
	 * @return Manifest[]
	 */
	public static function chain_for( string $id ): array {
		$chain   = [];
		$current = self::get( $id );
		$guard   = 0;

		while ( null !== $current && $guard++ < 1000 ) {
			array_unshift( $chain, $current );

			if ( Manifest::TYPE_INCREMENTAL !== $current->type() ) {
				return $chain;
			}

			$current = self::get( $current->parent() );
		}

		return [];
	}

	/**
	 * The newest complete set of the given chain head, i.e. the set the next
	 * incremental backup builds on.
	 */
	public static function chain_tip( string $chain_id ): ?Manifest {
		foreach ( self::all() as $manifest ) {
			if ( $manifest->chain() === $chain_id && $manifest->is_complete() ) {
				return $manifest;
			}
		}
		return null;
	}

	/**
	 * Sets that build on the given one (direct and indirect incrementals).
	 *
	 * @return string[]
	 */
	public static function dependents( string $id ): array {
		$manifest = self::get( $id );
		if ( null === $manifest || '' === $manifest->chain() ) {
			return [];
		}

		$out = [];
		foreach ( self::all() as $other ) {
			if ( $other->chain() === $manifest->chain() && $other->created() > $manifest->created() && $other->id() !== $id ) {
				$out[] = $other->id();
			}
		}
		return $out;
	}

	/**
	 * Reconcile the catalog with the manifests in the local backup directory.
	 */
	public static function rescan_local(): int {
		$dir  = Paths::backup_dir();
		$data = self::load();

		$found = [];
		foreach ( (array) glob( $dir . '/*.manifest.json' ) as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file inside the protected backup directory.
			$manifest = Manifest::from_json( (string) file_get_contents( (string) $path ) );
			if ( null === $manifest || ! $manifest->is_complete() ) {
				continue;
			}
			if ( ! self::local_files_present( $manifest ) ) {
				continue;
			}

			$found[ $manifest->id() ] = true;

			if ( ! isset( $data[ $manifest->id() ] ) ) {
				$data[ $manifest->id() ] = [
					'manifest'  => $manifest->data,
					'locations' => [],
				];
			}
			$data[ $manifest->id() ]['locations'][] = Storage_Local::ID;
			$data[ $manifest->id() ]['locations']   = array_values( array_unique( $data[ $manifest->id() ]['locations'] ) );
		}

		foreach ( $data as $id => $entry ) {
			if ( ! isset( $found[ $id ] ) ) {
				$data[ $id ]['locations'] = array_values( array_diff( (array) $entry['locations'], [ Storage_Local::ID ] ) );
				if ( empty( $data[ $id ]['locations'] ) ) {
					unset( $data[ $id ] );
				}
			}
		}

		self::store( $data );

		return count( $found );
	}

	/**
	 * Merge the manifests found on a remote destination.
	 *
	 * @param Manifest[] $manifests Manifests listed on the destination.
	 */
	public static function merge_remote( string $destination, array $manifests ): void {
		$data = self::load();

		$seen = [];
		foreach ( $manifests as $manifest ) {
			$seen[ $manifest->id() ] = true;
			if ( ! isset( $data[ $manifest->id() ] ) ) {
				$data[ $manifest->id() ] = [
					'manifest'  => $manifest->data,
					'locations' => [],
				];
			}
			$data[ $manifest->id() ]['locations'][] = $destination;
			$data[ $manifest->id() ]['locations']   = array_values( array_unique( $data[ $manifest->id() ]['locations'] ) );
		}

		foreach ( $data as $id => $entry ) {
			if ( ! isset( $seen[ $id ] ) && in_array( $destination, (array) $entry['locations'], true ) ) {
				$data[ $id ]['locations'] = array_values( array_diff( (array) $entry['locations'], [ $destination ] ) );
				if ( empty( $data[ $id ]['locations'] ) ) {
					unset( $data[ $id ] );
				}
			}
		}

		self::store( $data );
	}

	public static function local_files_present( Manifest $manifest ): bool {
		$dir = Paths::backup_dir();
		foreach ( $manifest->all_file_names() as $name ) {
			if ( ! is_file( $dir . '/' . $name ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Most recent successful backup of any kind.
	 */
	public static function latest(): ?Manifest {
		foreach ( self::all() as $manifest ) {
			if ( $manifest->is_complete() ) {
				return $manifest;
			}
		}
		return null;
	}

	/**
	 * Bytes used by local copies.
	 */
	public static function local_usage(): int {
		$total = 0;
		foreach ( self::load() as $entry ) {
			if ( in_array( Storage_Local::ID, (array) $entry['locations'], true ) ) {
				$total += ( new Manifest( (array) $entry['manifest'] ) )->total_size();
			}
		}
		return $total;
	}
}
