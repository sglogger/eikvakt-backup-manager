<?php
/**
 * Configured storage destinations.
 *
 * "This server" always exists and is not stored; every other destination is
 * an entry in the destinations option with its credentials sealed by
 * Secret_Cipher. Backups are copied to every enabled destination at once.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Destinations {

	/**
	 * Destination types, their fields and which of those are secret.
	 *
	 * @return array<string, array{label:string, fields:array<string, string>, secrets:string[], oauth:bool}>
	 */
	public static function types(): array {
		return [
			'ftp'      => [
				'label'   => __( 'FTP / FTPS', 'eikvakt-backup-manager' ),
				'fields'  => [
					'host'     => 'text',
					'port'     => 'number',
					'username' => 'text',
					'password' => 'password',
					'path'     => 'text',
					'ssl'      => 'checkbox',
					'tls_pin'  => 'text',
					'passive'  => 'checkbox',
				],
				'secrets' => [ 'password' ],
				'oauth'   => false,
			],
			'sftp'     => [
				'label'   => __( 'SFTP (SSH)', 'eikvakt-backup-manager' ),
				'fields'  => [
					'host'           => 'text',
					'port'           => 'number',
					'username'       => 'text',
					'password'       => 'password',
					'private_key'    => 'textarea',
					'key_passphrase' => 'password',
					'path'           => 'text',
					'fingerprint'    => 'text',
				],
				'secrets' => [ 'password', 'private_key', 'key_passphrase' ],
				'oauth'   => false,
			],
			'dropbox'  => [
				'label'   => __( 'Dropbox', 'eikvakt-backup-manager' ),
				'fields'  => [
					'own_app'    => 'checkbox',
					'app_key'    => 'text',
					'app_secret' => 'password',
					'path'       => 'text',
				],
				'secrets' => [ 'app_secret', 'refresh_token' ],
				'oauth'   => true,
			],
			'onedrive' => [
				'label'   => __( 'Microsoft OneDrive', 'eikvakt-backup-manager' ),
				'fields'  => [
					'client_id'     => 'text',
					'client_secret' => 'password',
					'tenant'        => 'text',
					'path'          => 'text',
				],
				'secrets' => [ 'client_secret', 'refresh_token' ],
				'oauth'   => true,
			],
		];
	}

	public static function field_label( string $field ): string {
		$labels = [
			'host'           => __( 'Host', 'eikvakt-backup-manager' ),
			'port'           => __( 'Port', 'eikvakt-backup-manager' ),
			'username'       => __( 'User name', 'eikvakt-backup-manager' ),
			'password'       => __( 'Password', 'eikvakt-backup-manager' ),
			'path'           => __( 'Folder', 'eikvakt-backup-manager' ),
			'ssl'            => __( 'Use FTPS (TLS, certificate verified)', 'eikvakt-backup-manager' ),
			'tls_pin'        => __( 'Pinned TLS key (self-signed servers only)', 'eikvakt-backup-manager' ),
			'passive'        => __( 'Passive mode', 'eikvakt-backup-manager' ),
			'private_key'    => __( 'Private key (instead of a password)', 'eikvakt-backup-manager' ),
			'key_passphrase' => __( 'Key passphrase', 'eikvakt-backup-manager' ),
			'fingerprint'    => __( 'Pinned host key fingerprint', 'eikvakt-backup-manager' ),
			'own_app'        => __( 'Own Dropbox app', 'eikvakt-backup-manager' ),
			'app_key'        => __( 'App key', 'eikvakt-backup-manager' ),
			'app_secret'     => __( 'App secret', 'eikvakt-backup-manager' ),
			'client_id'      => __( 'Application (client) ID', 'eikvakt-backup-manager' ),
			'client_secret'  => __( 'Client secret', 'eikvakt-backup-manager' ),
			'tenant'         => __( 'Tenant', 'eikvakt-backup-manager' ),
		];
		return $labels[ $field ] ?? $field;
	}

	/**
	 * @return array<string, array<string, mixed>> Stored destinations by id.
	 */
	public static function all(): array {
		$stored = get_option( Installer::OPTION_DESTINATIONS, [] );
		$out    = [];
		foreach ( is_array( $stored ) ? $stored : [] as $destination ) {
			if ( isset( $destination['id'], $destination['type'] ) && isset( self::types()[ $destination['type'] ] ) ) {
				$out[ (string) $destination['id'] ] = $destination;
			}
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * Enabled remote destinations.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function enabled(): array {
		return array_filter( self::all(), static fn( $d ) => ! empty( $d['enabled'] ) );
	}

	/**
	 * @param array<string, mixed> $destination Destination with sealed secrets.
	 */
	public static function save( array $destination ): void {
		$all                       = self::all();
		$all[ $destination['id'] ] = $destination;
		update_option( Installer::OPTION_DESTINATIONS, array_values( $all ), false );
	}

	public static function delete( string $id ): void {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( Installer::OPTION_DESTINATIONS, array_values( $all ), false );
		delete_transient( 'ekbm_token_' . $id );
	}

	/**
	 * Display name of a destination id, including "This server".
	 */
	public static function name( string $id ): string {
		if ( Storage_Local::ID === $id ) {
			return __( 'This server', 'eikvakt-backup-manager' );
		}
		$destination = self::get( $id );
		return null === $destination ? $id : (string) $destination['name'];
	}

	/**
	 * Config with secrets decrypted, for a driver.
	 *
	 * @param array<string, mixed> $destination Destination.
	 * @return array<string, mixed>
	 */
	public static function open_config( array $destination ): array {
		$config  = (array) ( $destination['config'] ?? [] );
		$secrets = self::types()[ $destination['type'] ]['secrets'] ?? [];

		foreach ( $secrets as $key ) {
			if ( isset( $config[ $key ] ) && '' !== $config[ $key ] ) {
				$config[ $key ] = Secret_Cipher::decrypt( (string) $config[ $key ] );
			}
		}

		return $config;
	}

	/**
	 * Driver for a destination id ('local' included).
	 */
	public static function driver( string $id ): Storage_Driver {
		if ( Storage_Local::ID === $id ) {
			return new Storage_Local();
		}

		$destination = self::get( $id );
		if ( null === $destination ) {
			/* translators: %s: destination id */
			throw new \RuntimeException( esc_html( sprintf( __( 'Unknown destination %s.', 'eikvakt-backup-manager' ), $id ) ) );
		}

		$config = self::open_config( $destination );

		switch ( $destination['type'] ) {
			case 'ftp':
				return new Storage_Ftp( $config );
			case 'sftp':
				return new Storage_Sftp( $config );
			case 'dropbox':
				return new Storage_Dropbox( $config, $id );
			case 'onedrive':
				return new Storage_Onedrive( $config, $id );
		}

		throw new \RuntimeException( 'Unknown destination type.' );
	}

	/**
	 * Test a destination; on SFTP, pin the host key on first success.
	 *
	 * @return true|\WP_Error
	 */
	public static function test( string $id ) {
		if ( Storage_Local::ID !== $id && Util::is_playground() ) {
			return new \WP_Error( 'ekbm_playground', Util::playground_notice() );
		}

		try {
			$driver = self::driver( $id );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'ekbm_destination', Util::message( $e ) );
		}

		$result = $driver->test();

		if ( true === $result && $driver instanceof Storage_Sftp && '' !== $driver->fingerprint ) {
			$destination = self::get( $id );
			if ( null !== $destination && empty( $destination['config']['fingerprint'] ) ) {
				$destination['config']['fingerprint'] = $driver->fingerprint;
				self::save( $destination );
			}
		}

		return $result;
	}

	/**
	 * Is an OAuth destination connected (has a refresh token)?
	 *
	 * @param array<string, mixed> $destination Destination.
	 */
	public static function is_connected( array $destination ): bool {
		if ( empty( self::types()[ $destination['type'] ]['oauth'] ) ) {
			return true;
		}
		return '' !== (string) ( $destination['config']['refresh_token'] ?? '' );
	}

	/**
	 * Read the manifests stored on a destination and merge them into the
	 * catalog. Used by "Rescan" and to find backups made by another site
	 * (migration through shared storage).
	 */
	public static function rescan( string $id ): int {
		$driver    = self::driver( $id );
		$names     = $driver->list_names();
		$manifests = [];
		$present   = array_flip( $names );

		foreach ( $names as $name ) {
			if ( ! str_ends_with( $name, '.manifest.json' ) ) {
				continue;
			}

			$manifest = Manifest::from_json( $driver->get_contents( $name ) );
			if ( null === $manifest || ! $manifest->is_complete() ) {
				continue;
			}

			foreach ( $manifest->all_file_names() as $file ) {
				if ( ! isset( $present[ $file ] ) ) {
					continue 2;
				}
			}

			$manifests[] = $manifest;
		}

		Catalog::merge_remote( $id, $manifests );

		return count( $manifests );
	}
}
