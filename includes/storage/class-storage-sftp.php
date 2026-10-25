<?php
/**
 * SFTP (SSH File Transfer Protocol) via phpseclib.
 *
 * Pure PHP, so it works without the ssh2 extension, which almost no shared
 * host has. Authentication by password or private key. The server's host key
 * fingerprint is pinned on the first successful connection and checked on
 * every later one, so a man-in-the-middle cannot silently receive the
 * backups.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Storage_Sftp implements Storage_Driver {

	private const CHUNK = 4 * 1048576;

	/** @var array<string, mixed> */
	private array $config;

	/** @var \phpseclib4\Net\SFTP|null */
	private $sftp = null;

	/** Fingerprint seen on the last connection, for pinning by the caller. */
	public string $fingerprint = '';

	/**
	 * @param array<string, mixed> $config Destination config with secrets opened.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	public static function is_available(): bool {
		return is_readable( EKBM_DIR . 'vendor/autoload.php' );
	}

	/**
	 * @return \phpseclib4\Net\SFTP
	 */
	private function connect() {
		if ( null !== $this->sftp ) {
			return $this->sftp;
		}

		if ( ! self::is_available() ) {
			throw new \RuntimeException( esc_html__( 'The SFTP library is missing from the plugin. Reinstall the plugin.', 'eikvakt-backup-manager' ) );
		}

		require_once EKBM_DIR . 'vendor/autoload.php';

		$host = (string) ( $this->config['host'] ?? '' );
		$sftp = new \phpseclib4\Net\SFTP( $host, (int) ( $this->config['port'] ?? 22 ), 20 );

		$fingerprint = (string) $sftp->getServerPublicHostKey();
		if ( '' === $fingerprint ) {
			/* translators: %s: host name */
			throw new \RuntimeException( esc_html( sprintf( __( 'Could not connect to %s.', 'eikvakt-backup-manager' ), $host ) ) );
		}

		$this->fingerprint = 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $fingerprint, true ) ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OpenSSH fingerprint notation.
		$pinned            = (string) ( $this->config['fingerprint'] ?? '' );

		if ( '' !== $pinned && ! hash_equals( $pinned, $this->fingerprint ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: 1: expected fingerprint, 2: received fingerprint */
						__( 'The server\'s host key has changed (expected %1$s, got %2$s). Nothing was sent. If the change is expected, clear the fingerprint in the destination settings.', 'eikvakt-backup-manager' ),
						$pinned,
						$this->fingerprint
					)
				)
			);
		}

		$username = (string) ( $this->config['username'] ?? '' );
		$key_text = (string) ( $this->config['private_key'] ?? '' );

		if ( '' !== $key_text ) {
			try {
				$key = \phpseclib4\Crypt\PublicKeyLoader::load( $key_text, (string) ( $this->config['key_passphrase'] ?? '' ) ?: null );
			} catch ( \Throwable $e ) {
				throw new \RuntimeException( esc_html__( 'The private key could not be read. Check the key and its passphrase.', 'eikvakt-backup-manager' ) );
			}
			$ok = $sftp->login( $username, $key );
		} else {
			$ok = $sftp->login( $username, (string) ( $this->config['password'] ?? '' ) );
		}

		if ( ! $ok ) {
			throw new \RuntimeException( esc_html__( 'The SFTP server rejected the login.', 'eikvakt-backup-manager' ) );
		}

		$dir = $this->dir();
		if ( '' !== $dir && ! $sftp->is_dir( $dir ) ) {
			$sftp->mkdir( $dir, -1, true );
		}

		$this->sftp = $sftp;
		return $sftp;
	}

	private function dir(): string {
		$path = trim( (string) ( $this->config['path'] ?? '' ) );
		if ( '' === $path ) {
			return '';
		}
		// Relative paths are relative to the login directory, as in any SFTP client.
		return rtrim( $path, '/' );
	}

	private function remote( string $name ): string {
		if ( ! Util::is_safe_filename( $name ) ) {
			throw new \RuntimeException( 'Invalid file name.' );
		}
		return '' !== $this->dir() ? $this->dir() . '/' . $name : $name;
	}

	public function test() {
		try {
			$sftp = $this->connect();
			$name = $this->remote( 'ekbm-test-' . Util::random_token( 4 ) . '.txt' );
			try {
				$sftp->put( $name, 'ok' );
			} catch ( \Throwable $e ) {
				return new \WP_Error( 'ekbm_sftp', __( 'Connected, but the folder is not writable.', 'eikvakt-backup-manager' ) );
			}
			$sftp->delete( $name, false );
			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'ekbm_sftp', Util::message( $e ) );
		}
	}

	public function upload( string $local, string $name, array $state, float $deadline ): array {
		$sftp   = $this->connect();
		$remote = $this->remote( $name );
		$size   = (int) filesize( $local );
		$offset = (int) ( $state['offset'] ?? 0 );

		// phpseclib writes at an offset without truncating, so a stale file of
		// the same name must go before the first chunk.
		if ( 0 === $offset && $sftp->file_exists( $remote ) ) {
			$sftp->delete( $remote, false );
		}

		$handle = fopen( $local, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a local archive.
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not open the local file.' );
		}

		fseek( $handle, $offset );

		do {
			$chunk = (string) fread( $handle, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streaming a local archive.
			if ( '' === $chunk && $offset < $size ) {
				break;
			}
			try {
				$sftp->put( $remote, $chunk, \phpseclib4\Net\SFTP::SOURCE_STRING, $offset );
			} catch ( \Throwable $e ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a local archive.
				throw new \RuntimeException( esc_html__( 'The SFTP upload failed.', 'eikvakt-backup-manager' ) );
			}
			$offset += strlen( $chunk );
		} while ( $offset < $size && microtime( true ) < $deadline );

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a local archive.

		return [
			'offset'      => $offset,
			'done'        => $offset >= $size,
			'fingerprint' => $this->fingerprint,
		];
	}

	public function download( string $name, string $local, array $state, float $deadline ): array {
		$sftp   = $this->connect();
		$remote = $this->remote( $name );
		$size   = (int) ( $state['size'] ?? $sftp->filesize( $remote ) );
		$offset = (int) ( $state['offset'] ?? 0 );

		$handle = fopen( $local, $offset > 0 ? 'ab' : 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming into the work directory.
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not write the local file.' );
		}

		while ( $offset < $size && microtime( true ) < $deadline ) {
			try {
				$chunk = $sftp->get( $remote, null, $offset, self::CHUNK );
			} catch ( \Throwable $e ) {
				$chunk = null;
			}
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming into the work directory.
				throw new \RuntimeException( esc_html__( 'The SFTP download failed.', 'eikvakt-backup-manager' ) );
			}
			fwrite( $handle, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming into the work directory.
			$offset += strlen( $chunk );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming into the work directory.

		return [
			'offset' => $offset,
			'size'   => $size,
			'done'   => $offset >= $size,
		];
	}

	public function list_names(): array {
		$list  = $this->connect()->nlist( '' !== $this->dir() ? $this->dir() : '.' );
		$names = [];
		foreach ( (array) $list as $name ) {
			if ( Util::is_safe_filename( (string) $name ) ) {
				$names[] = (string) $name;
			}
		}
		return $names;
	}

	public function get_contents( string $name ): string {
		$sftp   = $this->connect();
		$remote = $this->remote( $name );
		try {
			$contents = $sftp->get( $remote );
		} catch ( \Throwable $e ) {
			$contents = null;
		}
		return is_string( $contents ) ? $contents : '';
	}

	public function delete( string $name ): bool {
		$sftp   = $this->connect();
		$remote = $this->remote( $name );
		try {
			$sftp->delete( $remote, false );
			return true;
		} catch ( \Throwable $e ) {
			return ! $sftp->file_exists( $remote );
		}
	}
}
