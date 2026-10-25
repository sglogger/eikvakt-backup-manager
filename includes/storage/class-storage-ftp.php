<?php
/**
 * FTP and FTPS (explicit TLS), over cURL.
 *
 * cURL rather than PHP's ftp extension because ftp_ssl_connect() never
 * verifies the server certificate — an attacker on the network could pose as
 * the backup server and receive credentials and backups. Here:
 *
 *   - FTPS requires TLS for the control and the data connection
 *     (CURLUSESSL_ALL): a server that cannot do TLS is an error, never a
 *     silent fall-back to plain FTP;
 *   - the certificate is verified against WordPress's CA bundle, including
 *     the host name;
 *   - a server with a self-signed certificate (typical for a NAS) can be
 *     trusted by pinning its public key (`sha256//…`), which the connection
 *     test shows for comparison. Without a pin, an unverifiable certificate
 *     is refused.
 *
 * Plain FTP sends the password unencrypted; the settings screen says so and
 * recommends FTPS or SFTP. It is offered because some backup boxes speak
 * nothing else.
 *
 * Transfers are chunked and resumable: uploads append 8 MiB at a time,
 * starting from the size the server reports (so a chunk that was sent but
 * not recorded is never appended twice); downloads use byte ranges.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Storage_Ftp implements Storage_Driver {

	private const CHUNK = 8 * 1048576;

	/** @var array<string, mixed> */
	private array $config;

	/** Public-key pin of the certificate seen by the last failed TLS test. */
	public string $seen_pin = '';

	/**
	 * @param array<string, mixed> $config Destination config with secrets opened.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	public static function is_available(): bool {
		return function_exists( 'curl_init' );
	}

	private function tls(): bool {
		return ! empty( $this->config['ssl'] );
	}

	/**
	 * Folder as entered: relative paths start in the login directory (as in
	 * any FTP client), a leading slash makes it absolute.
	 */
	private function dir(): string {
		$path = trim( (string) ( $this->config['path'] ?? '' ) );
		if ( '' === trim( $path, '/' ) ) {
			return '';
		}
		return ( str_starts_with( $path, '/' ) ? '/' : '' ) . trim( $path, '/' );
	}

	/**
	 * cURL URL of the folder (trailing slash) or of a file in it.
	 */
	private function url( string $name = '' ): string {
		if ( '' !== $name && ! Util::is_safe_filename( $name ) ) {
			throw new \RuntimeException( 'Invalid file name.' );
		}

		$host = (string) ( $this->config['host'] ?? '' );
		if ( 1 !== preg_match( '/^[a-z0-9.\-]+$|^\[[0-9a-f:]+\]$/i', $host ) ) {
			throw new \RuntimeException( esc_html__( 'The FTP host name is not valid.', 'eikvakt-backup-manager' ) );
		}

		$dir      = $this->dir();
		$segments = array_map( 'rawurlencode', array_values( array_filter( explode( '/', $dir ), 'strlen' ) ) );

		// In an FTP URL the path is relative to the login directory; an
		// absolute path needs its leading slash encoded.
		$path = ( str_starts_with( $dir, '/' ) ? '%2F' : '' ) . implode( '/', $segments );
		$path = '' !== $path ? $path . '/' : '';

		return 'ftp://' . $host . ':' . (int) ( $this->config['port'] ?? 21 ) . '/' . $path . $name;
	}

	/**
	 * A configured cURL handle.
	 *
	 * @param array<int, mixed> $options Additional options.
	 * @return \CurlHandle
	 */
	private function handle( string $url, array $options = [], bool $verify = true ) {
		if ( ! self::is_available() ) {
			throw new \RuntimeException( esc_html__( 'The PHP cURL extension is not installed on this server.', 'eikvakt-backup-manager' ) );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- the WordPress HTTP API speaks HTTP only; FTP with verified TLS needs cURL directly.
		$curl = curl_init( $url );

		$base = [
			CURLOPT_USERNAME                => (string) ( $this->config['username'] ?? '' ),
			CURLOPT_PASSWORD                => (string) ( $this->config['password'] ?? '' ),
			CURLOPT_CONNECTTIMEOUT          => 20,
			CURLOPT_TIMEOUT                 => 300,
			CURLOPT_RETURNTRANSFER          => true,
			CURLOPT_FTP_USE_EPSV            => true,
			CURLOPT_PROTOCOLS               => CURLPROTO_FTP,
			CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
		];

		if ( isset( $this->config['passive'] ) && empty( $this->config['passive'] ) ) {
			$base[ CURLOPT_FTPPORT ] = '-';
		}

		if ( $this->tls() ) {
			$pin = trim( (string) ( $this->config['tls_pin'] ?? '' ) );

			$base[ CURLOPT_USE_SSL ] = CURLUSESSL_ALL;
			$base[ CURLOPT_CAINFO ]  = ABSPATH . WPINC . '/certificates/ca-bundle.crt';

			if ( '' !== $pin ) {
				// Self-signed server: trust exactly this key, nothing else.
				$base[ CURLOPT_PINNEDPUBLICKEY ] = $pin;
				$base[ CURLOPT_SSL_VERIFYPEER ]  = false;
				$base[ CURLOPT_SSL_VERIFYHOST ]  = 0;
			} else {
				$base[ CURLOPT_SSL_VERIFYPEER ] = $verify;
				$base[ CURLOPT_SSL_VERIFYHOST ] = $verify ? 2 : 0;
			}
		}

		curl_setopt_array( $curl, $base );
		curl_setopt_array( $curl, $options );
		// phpcs:enable

		return $curl;
	}

	/**
	 * Run a handle; throw with a readable message on failure.
	 *
	 * @param \CurlHandle $curl Handle.
	 * @return string Response body.
	 */
	private function run( $curl ): string {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- see handle().
		$body  = curl_exec( $curl );
		$errno = curl_errno( $curl );
		$error = curl_error( $curl );
		curl_close( $curl );
		// phpcs:enable

		if ( 0 !== $errno ) {
			throw new \RuntimeException( esc_html( self::explain( $errno, $error ) ) );
		}

		return is_string( $body ) ? $body : '';
	}

	private static function explain( int $errno, string $error ): string {
		switch ( $errno ) {
			case 64: // CURLE_USE_SSL_FAILED.
				return __( 'The server does not support FTPS (TLS). Nothing was sent. Use SFTP, enable TLS on the server, or — only on a trusted network — switch FTPS off.', 'eikvakt-backup-manager' );
			case 60: // CURLE_PEER_FAILED_VERIFICATION.
			case 77: // CURLE_SSL_CACERT_BADFILE.
				return __( 'The server\'s TLS certificate could not be verified, so nothing was sent. If this is your own server with a self-signed certificate, pin its key (see "Test").', 'eikvakt-backup-manager' );
			case 90: // CURLE_SSL_PINNEDPUBKEYNOTMATCH.
				return __( 'The server\'s TLS key does not match the pinned key. Nothing was sent. If the certificate was replaced on purpose, update the pinned key.', 'eikvakt-backup-manager' );
			case 67: // CURLE_LOGIN_DENIED.
				return __( 'The FTP server rejected the user name or password.', 'eikvakt-backup-manager' );
			case 6:  // CURLE_COULDNT_RESOLVE_HOST.
			case 7:  // CURLE_COULDNT_CONNECT.
			case 28: // CURLE_OPERATION_TIMEDOUT.
				return __( 'Could not connect to the FTP server.', 'eikvakt-backup-manager' );
		}
		return 'FTP: ' . $error;
	}

	/**
	 * Remote size of a file, or -1 when it does not exist.
	 */
	private function remote_size( string $name ): int {
		$curl = $this->handle(
			$this->url( $name ),
			[
				CURLOPT_NOBODY   => true,
				CURLOPT_FILETIME => true,
			]
		);
		// phpcs:disable WordPress.WP.AlternativeFunctions -- see handle().
		curl_exec( $curl );
		$errno = curl_errno( $curl );
		$size  = (int) curl_getinfo( $curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD );
		curl_close( $curl );
		// phpcs:enable

		return 0 === $errno ? max( 0, $size ) : -1;
	}

	/**
	 * Public-key pin (`sha256//base64`) of the server's certificate, read
	 * without verification — only to show it to the administrator for
	 * comparison, never to trust it automatically.
	 */
	private function read_pin(): string {
		$curl = $this->handle(
			$this->url(),
			[
				CURLOPT_DIRLISTONLY => true,
				CURLOPT_CERTINFO    => true,
				CURLOPT_TIMEOUT     => 20,
			],
			false
		);
		// phpcs:disable WordPress.WP.AlternativeFunctions -- see handle().
		curl_exec( $curl );
		$info = curl_getinfo( $curl, CURLINFO_CERTINFO );
		curl_close( $curl );
		// phpcs:enable

		$pem = is_array( $info ) && isset( $info[0]['Cert'] ) ? (string) $info[0]['Cert'] : '';
		if ( '' === $pem || ! function_exists( 'openssl_pkey_get_public' ) ) {
			return '';
		}

		$key     = openssl_pkey_get_public( $pem );
		$details = false !== $key ? openssl_pkey_get_details( $key ) : false;
		if ( ! is_array( $details ) || empty( $details['key'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the PEM body of a public key.
		$der = base64_decode( (string) preg_replace( '/-----[^-]+-----|\s+/', '', (string) $details['key'] ), true );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- cURL's pin notation.
		return false === $der ? '' : 'sha256//' . base64_encode( hash( 'sha256', $der, true ) );
	}

	public function test() {
		$name = 'ekbm-test-' . Util::random_token( 4 ) . '.txt';

		try {
			$this->put( $name, 'ok', 0 );
			$this->delete( $name );
			return true;
		} catch ( \RuntimeException $e ) {
			$message = Util::message( $e );

			if ( $this->tls() && '' === trim( (string) ( $this->config['tls_pin'] ?? '' ) ) && str_contains( $message, 'certificate' ) ) {
				$this->seen_pin = $this->read_pin();
				if ( '' !== $this->seen_pin ) {
					$message .= ' ' . sprintf(
						/* translators: %s: public key pin */
						__( 'The server presented the key %s. Compare it with the key on your server; only if it matches, enter it as pinned key.', 'eikvakt-backup-manager' ),
						$this->seen_pin
					);
				}
			}

			return new \WP_Error( 'ekbm_ftp', $message );
		}
	}

	/**
	 * Write data at an offset: store at 0, append otherwise.
	 */
	private function put( string $name, string $data, int $offset ): void {
		$stream = fopen( 'php://temp', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory stream.
		fwrite( $stream, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- in-memory stream.
		rewind( $stream );

		$curl = $this->handle(
			$this->url( $name ),
			[
				CURLOPT_UPLOAD     => true,
				CURLOPT_INFILE     => $stream,
				CURLOPT_INFILESIZE => strlen( $data ),
				CURLOPT_APPEND     => $offset > 0,
			]
		);

		try {
			$this->run( $curl );
		} finally {
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory stream.
		}
	}

	public function upload( string $local, string $name, array $state, float $deadline ): array {
		$size = (int) filesize( $local );

		// Resume from what the server actually has, not from what we
		// remember: a chunk sent just before a timeout must not go twice.
		$offset = 0;
		if ( ! empty( $state['started'] ) ) {
			$offset = max( 0, $this->remote_size( $name ) );
			if ( $offset > $size ) {
				$offset = 0;
			}
		}

		$handle = fopen( $local, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a local archive.
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not open the local file.' );
		}
		fseek( $handle, $offset );

		do {
			$chunk = (string) fread( $handle, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streaming a local archive.
			if ( '' === $chunk && $offset > 0 ) {
				break;
			}

			try {
				$this->put( $name, $chunk, $offset );
			} catch ( \RuntimeException $e ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a local archive.
				throw $e;
			}

			$offset += strlen( $chunk );
		} while ( $offset < $size && microtime( true ) < $deadline );

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a local archive.

		return [
			'started' => true,
			'done'    => $offset >= $size,
		];
	}

	public function download( string $name, string $local, array $state, float $deadline ): array {
		$size   = (int) ( $state['size'] ?? $this->remote_size( $name ) );
		$offset = is_file( $local ) && ! empty( $state['started'] ) ? (int) filesize( $local ) : 0;

		if ( $size < 0 ) {
			throw new \RuntimeException( esc_html__( 'The file does not exist on the FTP server.', 'eikvakt-backup-manager' ) );
		}

		$out = fopen( $local, $offset > 0 ? 'ab' : 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming into the work directory.
		if ( false === $out ) {
			throw new \RuntimeException( 'Could not write the local file.' );
		}

		while ( $offset < $size && microtime( true ) < $deadline ) {
			$end  = min( $size, $offset + self::CHUNK ) - 1;
			$curl = $this->handle( $this->url( $name ), [ CURLOPT_RANGE => $offset . '-' . $end ] );

			try {
				$data = $this->run( $curl );
			} catch ( \RuntimeException $e ) {
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming into the work directory.
				throw $e;
			}

			if ( '' === $data ) {
				break;
			}
			fwrite( $out, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming into the work directory.
			$offset += strlen( $data );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming into the work directory.

		return [
			'started' => true,
			'size'    => $size,
			'done'    => $offset >= $size,
		];
	}

	public function list_names(): array {
		$body  = $this->run( $this->handle( $this->url(), [ CURLOPT_DIRLISTONLY => true ] ) );
		$names = [];

		foreach ( preg_split( '/\r?\n/', $body ) ?: [] as $entry ) {
			$name = basename( trim( (string) $entry ) );
			if ( Util::is_safe_filename( $name ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	public function get_contents( string $name ): string {
		try {
			return $this->run( $this->handle( $this->url( $name ) ) );
		} catch ( \RuntimeException $e ) {
			return '';
		}
	}

	public function delete( string $name ): bool {
		if ( ! Util::is_safe_filename( $name ) ) {
			return false;
		}

		try {
			// DELE runs after cURL has changed into the folder of the URL.
			$this->run(
				$this->handle(
					$this->url(),
					[
						CURLOPT_NOBODY => true,
						CURLOPT_QUOTE  => [ 'DELE ' . $name ],
					]
				)
			);
			return true;
		} catch ( \RuntimeException $e ) {
			return $this->remote_size( $name ) < 0;
		}
	}
}
