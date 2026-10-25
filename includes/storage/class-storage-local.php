<?php
/**
 * "This server": the protected local backup directory.
 *
 * Every backup is written here first; the other destinations receive copies.
 * Whether the local copy is kept afterwards is a setting.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Storage_Local implements Storage_Driver {

	public const ID = 'local';

	private function path( string $name ): string {
		if ( ! Util::is_safe_filename( $name ) ) {
			throw new \RuntimeException( 'Invalid file name.' );
		}
		return Paths::backup_dir() . '/' . $name;
	}

	public function test() {
		if ( ! Paths::ensure() || ! wp_is_writable( Paths::backup_dir() ) ) {
			return new \WP_Error( 'ekbm_local', __( 'The backup directory is not writable.', 'eikvakt-backup-manager' ) );
		}
		return true;
	}

	public function upload( string $local, string $name, array $state, float $deadline ): array {
		$target = $this->path( $name );

		if ( wp_normalize_path( $local ) !== wp_normalize_path( $target ) && ! copy( $local, $target ) ) {
			throw new \RuntimeException( 'Could not copy the file into the backup directory.' );
		}

		return [ 'done' => true ];
	}

	public function download( string $name, string $local, array $state, float $deadline ): array {
		$source = $this->path( $name );

		if ( wp_normalize_path( $local ) !== wp_normalize_path( $source ) && ! copy( $source, $local ) ) {
			throw new \RuntimeException( 'Could not read the file from the backup directory.' );
		}

		return [ 'done' => true ];
	}

	public function list_names(): array {
		$names = [];
		foreach ( (array) scandir( Paths::backup_dir() ) as $name ) {
			$name = (string) $name;
			if ( Util::is_safe_filename( $name ) && is_file( Paths::backup_dir() . '/' . $name ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	public function get_contents( string $name ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file inside the protected backup directory.
		return (string) file_get_contents( $this->path( $name ) );
	}

	public function delete( string $name ): bool {
		$path = $this->path( $name );
		if ( ! is_file( $path ) ) {
			return true;
		}
		wp_delete_file( $path );
		return ! is_file( $path );
	}
}
