<?php
/**
 * One-click migration between two sites: migration keys on the source,
 * the pull endpoint they unlock, and the import (Pull_Job) on the target.
 *
 * How it works:
 *
 *   1. On the source site an administrator creates a backup and a migration
 *      key for it. The key carries the source's endpoint URL and a 256-bit
 *      random token. Only a hash of the token is stored on the source.
 *   2. On the target site the key is pasted into Migration → Import. The
 *      target downloads the backup set in chunks from the source's endpoint
 *      into its own protected backup directory.
 *   3. The administrator restores it on the target like any other backup,
 *      with the source URL and path replaced by the target's.
 *
 * The endpoint is the only way a backup leaves a site without an admin
 * session. It is bound to that one backup, expires after 24 hours (or when
 * revoked), and serves nothing but the files of that one backup set. It
 * exists only while a key does.
 *
 * Migration through shared storage works without a key: configure the same
 * Dropbox, OneDrive, FTP or SFTP destination on both sites and use "Rescan"
 * on the target.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Migration {

	public const KEY_PREFIX = 'EKBM1-';
	public const TTL        = DAY_IN_SECONDS;
	public const CHUNK      = 8 * 1048576;

	public function register(): void {
		add_action( 'wp_ajax_nopriv_ekbm_migrate', [ $this, 'endpoint' ] );
		add_action( 'wp_ajax_ekbm_migrate', [ $this, 'endpoint' ] );
	}

	/**
	 * @return array<string, array{backup:string, expires:int, created:int}>
	 */
	public static function keys(): array {
		$keys = array_filter(
			(array) get_option( Installer::OPTION_MIGRATION_KEYS, [] ),
			static fn( $k ) => is_array( $k ) && (int) ( $k['expires'] ?? 0 ) > time()
		);
		return $keys;
	}

	/**
	 * Create a key for a backup set held on this server.
	 */
	public static function create_key( string $backup_id ): string {
		$manifest = Catalog::get( $backup_id );
		if ( null === $manifest ) {
			return '';
		}

		foreach ( Catalog::chain_for( $backup_id ) as $set ) {
			if ( ! Catalog::local_files_present( $set ) ) {
				return '';
			}
		}

		$token = Util::random_token( 32 );
		$keys  = self::keys();

		$keys[ hash( 'sha256', $token ) ] = [
			'backup'  => $backup_id,
			'expires' => time() + self::TTL,
			'created' => time(),
		];
		update_option( Installer::OPTION_MIGRATION_KEYS, $keys, false );

		$payload = (string) wp_json_encode(
			[
				'u' => admin_url( 'admin-ajax.php' ),
				't' => $token,
				'b' => $backup_id,
			]
		);

		return self::KEY_PREFIX . OAuth::base64url( $payload );
	}

	public static function revoke_all(): void {
		delete_option( Installer::OPTION_MIGRATION_KEYS );
	}

	/**
	 * @return array{u:string, t:string, b:string}|null
	 */
	public static function parse_key( string $key ): ?array {
		$key = trim( $key );
		if ( ! str_starts_with( $key, self::KEY_PREFIX ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the key format produced by create_key().
		$json = base64_decode( strtr( substr( $key, strlen( self::KEY_PREFIX ) ), '-_', '+/' ), true );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;

		if ( ! is_array( $data ) || empty( $data['u'] ) || empty( $data['t'] ) || empty( $data['b'] ) ) {
			return null;
		}
		// Not wp_http_validate_url(): it refuses private addresses and
		// non-standard ports, and moving a site between two servers on an
		// internal network is a legitimate migration. The key comes from an
		// administrator, and the endpoint must be our own admin-ajax.php.
		$url    = (string) $data['u'];
		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( $scheme, [ 'http', 'https' ], true ) || ! str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/admin-ajax.php' ) ) {
			return null;
		}

		if ( ! Manifest::is_valid_id( (string) $data['b'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', (string) $data['t'] ) ) {
			return null;
		}

		return [
			'u' => (string) $data['u'],
			't' => (string) $data['t'],
			'b' => (string) $data['b'],
		];
	}

	/**
	 * The pull endpoint. Authenticated by the migration token only.
	 */
	public function endpoint(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authenticated by the migration token (256 bit, hashed at rest, expiring, bound to one backup); called server-to-server without a session.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : '';
		$op    = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		$from  = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		// phpcs:enable

		$keys  = self::keys();
		$entry = $keys[ hash( 'sha256', $token ) ] ?? null;

		if ( '' === $token || ! is_array( $entry ) ) {
			// Uniform answer and a small delay: no oracle for guessing.
			usleep( 250000 );
			wp_send_json_error( [ 'message' => 'invalid-key' ], 403 );
		}

		$chain = Catalog::chain_for( (string) $entry['backup'] );
		if ( [] === $chain ) {
			wp_send_json_error( [ 'message' => 'backup-missing' ], 404 );
		}

		if ( 'list' === $op ) {
			wp_send_json_success(
				[
					'manifests' => array_map( static fn( Manifest $m ) => $m->data, $chain ),
				]
			);
		}

		if ( 'chunk' !== $op ) {
			wp_send_json_error( [ 'message' => 'bad-request' ], 400 );
		}

		$allowed = [];
		foreach ( $chain as $set ) {
			foreach ( $set->all_file_names() as $file ) {
				$allowed[ $file ] = true;
			}
		}

		if ( ! isset( $allowed[ $name ] ) || ! Util::is_safe_filename( $name ) ) {
			wp_send_json_error( [ 'message' => 'not-in-backup' ], 404 );
		}

		$path = Paths::backup_dir() . '/' . $name;
		$size = is_file( $path ) ? (int) filesize( $path ) : -1;
		if ( $size < 0 ) {
			wp_send_json_error( [ 'message' => 'file-missing' ], 404 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a backup file to an authorised migration target.
		$handle = fopen( $path, 'rb' );
		fseek( $handle, $from );
		$data = (string) fread( $handle, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Ekbm-Size: ' . $size );
		header( 'Content-Length: ' . strlen( $data ) );
		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file content, not HTML.
		exit;
	}
}
