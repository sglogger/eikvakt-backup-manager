<?php
/**
 * The manifest of one backup set.
 *
 * A backup set is a manifest plus the files it lists: one or more ZIP parts
 * holding file content, a gzip SQL dump, and the file index. The manifest is
 * plain JSON, never encrypted, and holds no secrets — only what is needed to
 * list, chain and restore the set: its type, its parent in an incremental
 * chain, what it contains, and where the source site lived.
 *
 * Types:
 *   full         every selected file; starts a chain
 *   incremental  only files changed since `parent`; belongs to `chain`
 *   partial      a standalone copy of some components (manual or pre-update
 *                backups); never part of a chain
 *   database     database only; standalone
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

final class Manifest {

	public const TYPE_FULL        = 'full';
	public const TYPE_INCREMENTAL = 'incremental';
	public const TYPE_PARTIAL     = 'partial';
	public const TYPE_DATABASE    = 'database';

	public const KIND_FILES = 'files';
	public const KIND_DB    = 'db';
	public const KIND_INDEX = 'index';

	/** @var array<string, mixed> */
	public array $data;

	/**
	 * @param array<string, mixed> $data Manifest data.
	 */
	public function __construct( array $data ) {
		$this->data = array_merge(
			[
				'format'     => 1,
				'id'         => '',
				'type'       => self::TYPE_FULL,
				'parent'     => '',
				'chain'      => '',
				'created'    => 0,
				'trigger'    => 'manual',
				'label'      => '',
				'components' => [],
				'tables'     => [],
				'encrypted'  => false,
				'enc_files'  => false,
				'files'      => [],
				'stats'      => [],
				'site'       => [],
				'complete'   => false,
			],
			$data
		);
	}

	/**
	 * Build an id that sorts by time, says what it is, and cannot be
	 * guessed: `<site>_<yyyymmdd-hhmmss>_<type letter>_<random>`.
	 */
	public static function new_id( string $site_slug, string $type, int $time, string $random ): string {
		$slug = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $site_slug ) );
		$slug = trim( substr( $slug, 0, 40 ), '-' );
		$slug = '' === $slug ? 'site' : $slug;

		$letter = [
			self::TYPE_FULL        => 'f',
			self::TYPE_INCREMENTAL => 'i',
			self::TYPE_PARTIAL     => 'p',
			self::TYPE_DATABASE    => 'd',
		][ $type ] ?? 'x';

		return sprintf( '%s_%s_%s_%s', $slug, gmdate( 'Ymd-His', $time ), $letter, strtolower( $random ) );
	}

	/**
	 * Is this a well-formed backup id? Ids end up in file names and remote
	 * object names, so anything outside this shape is refused.
	 */
	public static function is_valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-z0-9-]{1,40}_\d{8}-\d{6}_[fipd]_[a-f0-9]{8,32}$/', $id );
	}

	public static function manifest_name( string $id ): string {
		return $id . '.manifest.json';
	}

	/**
	 * Is this the name of a file of set `$id`? Only the names a backup job
	 * writes are accepted — the manifest, ZIP parts, the SQL dump and the
	 * index, each optionally encrypted — so an upload, an import or a
	 * tampered manifest can never place anything else (a `.php` file, a path)
	 * in the backup directory.
	 */
	public static function is_set_file_name( string $id, string $name ): bool {
		if ( ! self::is_valid_id( $id ) ) {
			return false;
		}
		if ( self::manifest_name( $id ) === $name ) {
			return true;
		}
		return 1 === preg_match( '/^' . preg_quote( $id, '/' ) . '\.(?:files-\d{3,}\.zip|db\.sql\.gz|index\.gz)(?:\.enc)?$/', $name );
	}

	/**
	 * Does every file the manifest lists have a name of this set?
	 */
	public function has_valid_file_names(): bool {
		foreach ( $this->files() as $file ) {
			if ( ! self::is_set_file_name( $this->id(), $file['name'] ) ) {
				return false;
			}
		}
		return true;
	}

	public static function from_json( string $json ): ?self {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || ! isset( $data['id'] ) || ! self::is_valid_id( (string) $data['id'] ) ) {
			return null;
		}

		$manifest = new self( $data );

		return $manifest->has_valid_file_names() ? $manifest : null;
	}

	public function to_json(): string {
		return (string) wp_json_encode( $this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	public function id(): string {
		return (string) $this->data['id'];
	}

	public function type(): string {
		return (string) $this->data['type'];
	}

	public function parent(): string {
		return (string) $this->data['parent'];
	}

	/**
	 * Id of the full backup that heads this set's chain (its own id for a
	 * full backup, '' for standalone types).
	 */
	public function chain(): string {
		return (string) $this->data['chain'];
	}

	public function created(): int {
		return (int) $this->data['created'];
	}

	public function is_encrypted(): bool {
		return ! empty( $this->data['encrypted'] );
	}

	public function is_complete(): bool {
		return ! empty( $this->data['complete'] );
	}

	/**
	 * Marked "keep" by an administrator: never removed by retention. The flag
	 * lives in the catalog, not in the manifest file, and is filled in by
	 * Catalog::all()/get().
	 */
	public function is_kept(): bool {
		return ! empty( $this->data['keep'] );
	}

	/**
	 * @return string[]
	 */
	public function components(): array {
		return array_values( array_map( 'strval', (array) $this->data['components'] ) );
	}

	/**
	 * @return string[]
	 */
	public function tables(): array {
		return array_values( array_map( 'strval', (array) $this->data['tables'] ) );
	}

	public function has( string $component ): bool {
		return in_array( $component, $this->components(), true );
	}

	/**
	 * @return array<int, array{name:string, kind:string, size:int}>
	 */
	public function files(): array {
		$files = [];
		foreach ( (array) $this->data['files'] as $file ) {
			$files[] = [
				'name' => (string) ( $file['name'] ?? '' ),
				'kind' => (string) ( $file['kind'] ?? '' ),
				'size' => (int) ( $file['size'] ?? 0 ),
			];
		}
		return $files;
	}

	/**
	 * @return string[]
	 */
	public function files_of_kind( string $kind ): array {
		$names = [];
		foreach ( $this->files() as $file ) {
			if ( $kind === $file['kind'] ) {
				$names[] = $file['name'];
			}
		}
		return $names;
	}

	/**
	 * Every file of the set, the manifest itself last: an upload or download
	 * that stops half-way leaves a set without a manifest, which the catalog
	 * never mistakes for a usable backup.
	 *
	 * @return string[]
	 */
	public function all_file_names(): array {
		$names   = array_column( $this->files(), 'name' );
		$names[] = self::manifest_name( $this->id() );
		return $names;
	}

	public function add_file( string $name, string $kind, int $size ): void {
		foreach ( (array) $this->data['files'] as $i => $file ) {
			if ( ( $file['name'] ?? '' ) === $name ) {
				$this->data['files'][ $i ]['size'] = $size;
				return;
			}
		}

		$this->data['files'][] = [
			'name' => $name,
			'kind' => $kind,
			'size' => $size,
		];
	}

	public function total_size(): int {
		return (int) array_sum( array_column( $this->files(), 'size' ) );
	}

	/**
	 * Value from the `site` block (home, siteurl, abspath, content_dir,
	 * table_prefix, wp_version, ...).
	 */
	public function site( string $key ): string {
		return (string) ( $this->data['site'][ $key ] ?? '' );
	}

	public function stat( string $key ): int {
		return (int) ( $this->data['stats'][ $key ] ?? 0 );
	}
}
