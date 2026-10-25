<?php
/**
 * Dropbox (App folder access).
 *
 * Two ways to connect:
 *
 *   Plugin app (default)  The plugin's own registered Dropbox app, shown to
 *                         the user as "Eikvakt Backup Manager" under Connected
 *                         apps. Dropbox only redirects to pre-registered
 *                         URLs and every WordPress site has a different one,
 *                         so this uses Dropbox's no-redirect flow: after
 *                         "Allow", Dropbox displays a one-time code which the
 *                         administrator pastes into the plugin. PKCE makes
 *                         this safe without an app secret — the plugin ships
 *                         only the public app key, and the code is worthless
 *                         without the verifier that never leaves the site.
 *   Own app (advanced)    The administrator's own Dropbox app with key/secret
 *                         and a redirect back to this site.
 *
 * Either way, tokens are stored only on this site; the plugin author never
 * sees them and no server of the author is involved.
 *
 * Uploads use upload sessions in 8 MiB chunks, so files of any size are
 * transferred across requests; downloads use HTTP range requests.
 *
 * External service: https://www.dropbox.com — contacted only for a
 * destination the administrator has configured and connected. See readme.txt
 * "External services".
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Storage_Dropbox implements Storage_Driver {

	private const API     = 'https://api.dropboxapi.com/2/';
	private const CONTENT = 'https://content.dropboxapi.com/2/';
	private const CHUNK   = 8 * 1048576;

	/**
	 * App key of the plugin's registered Dropbox app. Public by design (it
	 * identifies the app; PKCE replaces the secret). Empty until the app is
	 * registered — the "own app" mode is then the only option. The key of
	 * the registered app "Eikvakt Backup Manager" (App folder, PKCE). Can be
	 * overridden with the EKBM_DROPBOX_APP_KEY constant or the
	 * `ekbm_dropbox_app_key` filter.
	 */
	public const PLUGIN_APP_KEY = 'kmqwpqsfux9ldf9';

	/** @var array<string, mixed> */
	private array $config;

	private string $id;

	/**
	 * @param array<string, mixed> $config Destination config with secrets opened.
	 */
	public function __construct( array $config, string $destination_id ) {
		$this->config = $config;
		$this->id     = $destination_id;
	}

	public static function plugin_app_key(): string {
		$key = defined( 'EKBM_DROPBOX_APP_KEY' ) ? (string) EKBM_DROPBOX_APP_KEY : self::PLUGIN_APP_KEY;

		/**
		 * Filters the app key of the built-in Dropbox app.
		 *
		 * @param string $key App key ('' = no built-in app).
		 */
		return trim( (string) apply_filters( 'ekbm_dropbox_app_key', $key ) );
	}

	/**
	 * Does this destination use the plugin's app (code paste flow)?
	 *
	 * @param array<string, mixed> $config Destination config.
	 */
	public static function uses_plugin_app( array $config ): bool {
		return empty( $config['own_app'] ) && '' !== self::plugin_app_key();
	}

	/**
	 * @param array<string, mixed> $config Destination config.
	 */
	private static function app_key( array $config ): string {
		return self::uses_plugin_app( $config ) ? self::plugin_app_key() : (string) ( $config['app_key'] ?? '' );
	}

	/**
	 * Token-endpoint client credentials: the app key, plus the secret only
	 * for an own app that has one.
	 *
	 * @param array<string, mixed> $config Destination config with secrets opened.
	 * @return array<string, string>
	 */
	private static function client( array $config ): array {
		$client = [ 'client_id' => self::app_key( $config ) ];
		if ( ! self::uses_plugin_app( $config ) && ! empty( $config['app_secret'] ) ) {
			$client['client_secret'] = (string) $config['app_secret'];
		}
		return $client;
	}

	/**
	 * @param array<string, mixed> $config Destination config.
	 */
	public static function authorize_url( array $config, string $challenge, string $state ): string {
		$args = [
			'client_id'             => rawurlencode( self::app_key( $config ) ),
			'response_type'         => 'code',
			'token_access_type'     => 'offline',
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
		];

		// Own app: redirect back to this site. Plugin app: no redirect —
		// Dropbox shows the code for the administrator to paste.
		if ( ! self::uses_plugin_app( $config ) ) {
			$args['redirect_uri'] = rawurlencode( OAuth::redirect_uri() );
			$args['state']        = $state;
		}

		return add_query_arg( $args, 'https://www.dropbox.com/oauth2/authorize' );
	}

	/**
	 * @param array<string, mixed> $config Destination config with secrets opened.
	 * @return string Refresh token.
	 */
	public static function exchange_code( array $config, string $code, string $verifier ): string {
		$body = array_merge(
			[
				'code'          => trim( $code ),
				'grant_type'    => 'authorization_code',
				'code_verifier' => $verifier,
			],
			self::client( $config )
		);
		if ( ! self::uses_plugin_app( $config ) ) {
			$body['redirect_uri'] = OAuth::redirect_uri();
		}

		$response = OAuth::http(
			'https://api.dropboxapi.com/oauth2/token',
			[
				'method' => 'POST',
				'body'   => $body,
			]
		);
		$data     = json_decode( $response['body'], true );

		if ( 200 !== $response['code'] || empty( $data['refresh_token'] ) ) {
			throw new \RuntimeException( 'Dropbox did not issue a refresh token.' );
		}

		return (string) $data['refresh_token'];
	}

	private function token(): string {
		if ( empty( $this->config['refresh_token'] ) ) {
			throw new \RuntimeException( esc_html__( 'This Dropbox destination is not connected yet. Use "Connect" on the Storage tab.', 'eikvakt-backup-manager' ) );
		}

		return OAuth::access_token(
			$this->id,
			function (): array {
				$body = array_merge(
					[
						'grant_type'    => 'refresh_token',
						'refresh_token' => (string) $this->config['refresh_token'],
					],
					self::client( $this->config )
				);

				$response = OAuth::http(
					'https://api.dropboxapi.com/oauth2/token',
					[
						'method' => 'POST',
						'body'   => $body,
					]
				);
				$data     = json_decode( $response['body'], true );

				if ( 200 !== $response['code'] || empty( $data['access_token'] ) ) {
					throw new \RuntimeException( esc_html__( 'Dropbox refused to renew access. Reconnect the destination.', 'eikvakt-backup-manager' ) );
				}

				return [ (string) $data['access_token'], (int) ( $data['expires_in'] ?? 14400 ) ];
			}
		);
	}

	private function folder(): string {
		$path = trim( (string) ( $this->config['path'] ?? '' ), '/' );
		return '' === $path ? '' : '/' . $path;
	}

	private function remote( string $name ): string {
		if ( ! Util::is_safe_filename( $name ) ) {
			throw new \RuntimeException( 'Invalid file name.' );
		}
		return $this->folder() . '/' . $name;
	}

	/**
	 * @param array<string, mixed> $payload JSON body.
	 * @return array<string, mixed>
	 */
	private function rpc( string $endpoint, array $payload ): array {
		$response = OAuth::http(
			self::API . $endpoint,
			[
				'method'  => 'POST',
				'headers' => [
					'Authorization' => 'Bearer ' . $this->token(),
					'Content-Type'  => 'application/json',
				],
				'body'    => (string) wp_json_encode( $payload ),
			]
		);

		$data = json_decode( $response['body'], true );

		if ( 200 !== $response['code'] ) {
			$summary = is_array( $data ) ? (string) ( $data['error_summary'] ?? '' ) : $response['body'];
			throw new \RuntimeException( esc_html( 'Dropbox: ' . $summary ) );
		}

		return is_array( $data ) ? $data : [];
	}

	/**
	 * @param array<string, mixed> $arg Dropbox-API-Arg header value.
	 * @return array{code:int, body:string, headers:array<string, string>}
	 */
	private function content( string $endpoint, array $arg, string $body, array $extra_headers = [] ): array {
		return OAuth::http(
			self::CONTENT . $endpoint,
			[
				'method'  => 'POST',
				'timeout' => 120,
				'headers' => array_merge(
					[
						'Authorization'   => 'Bearer ' . $this->token(),
						'Content-Type'    => 'application/octet-stream',
						// JSON with non-ASCII escaped, as the header requires.
						'Dropbox-API-Arg' => (string) wp_json_encode( $arg ),
					],
					$extra_headers
				),
				'body'    => $body,
			]
		);
	}

	public function test() {
		try {
			$name = 'ekbm-test-' . Util::random_token( 4 ) . '.txt';
			$res  = $this->content(
				'files/upload',
				[
					'path' => $this->remote( $name ),
					'mode' => 'overwrite',
					'mute' => true,
				],
				'ok'
			);
			if ( 200 !== $res['code'] ) {
				return new \WP_Error( 'ekbm_dropbox', 'Dropbox: ' . $res['body'] );
			}
			$this->delete( $name );
			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'ekbm_dropbox', Util::message( $e ) );
		}
	}

	public function upload( string $local, string $name, array $state, float $deadline ): array {
		$size   = (int) filesize( $local );
		$offset = (int) ( $state['offset'] ?? 0 );
		$sid    = (string) ( $state['session'] ?? '' );

		$handle = fopen( $local, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a local archive.
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not open the local file.' );
		}
		fseek( $handle, $offset );

		do {
			$chunk = (string) fread( $handle, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streaming a local archive.
			$last  = $offset + strlen( $chunk ) >= $size;

			if ( '' === $sid ) {
				$res = $this->content( 'files/upload_session/start', [ 'close' => false ], $chunk );
				if ( 200 !== $res['code'] ) {
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a local archive.
					throw new \RuntimeException( esc_html( 'Dropbox: ' . $res['body'] ) );
				}
				$sid     = (string) ( json_decode( $res['body'], true )['session_id'] ?? '' );
				$offset += strlen( $chunk );
				if ( ! $last ) {
					continue;
				}
				$chunk = '';
			}

			$cursor = [
				'session_id' => $sid,
				'offset'     => $offset,
			];

			if ( $last ) {
				$res = $this->content(
					'files/upload_session/finish',
					[
						'cursor' => $cursor,
						'commit' => [
							'path'       => $this->remote( $name ),
							'mode'       => 'overwrite',
							'autorename' => false,
							'mute'       => true,
						],
					],
					$chunk
				);
			} else {
				$res = $this->content(
					'files/upload_session/append_v2',
					[
						'cursor' => $cursor,
						'close'  => false,
					],
					$chunk
				);
			}

			if ( 200 !== $res['code'] ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a local archive.
				throw new \RuntimeException( esc_html( 'Dropbox: ' . $res['body'] ) );
			}

			$offset += strlen( $chunk );

			if ( $last ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a local archive.
				return [ 'done' => true ];
			}
		} while ( microtime( true ) < $deadline );

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a local archive.

		return [
			'session' => $sid,
			'offset'  => $offset,
			'done'    => false,
		];
	}

	public function download( string $name, string $local, array $state, float $deadline ): array {
		$offset = (int) ( $state['offset'] ?? 0 );
		$size   = (int) ( $state['size'] ?? -1 );

		if ( $size < 0 ) {
			$meta = $this->rpc( 'files/get_metadata', [ 'path' => $this->remote( $name ) ] );
			$size = (int) ( $meta['size'] ?? 0 );
		}

		$handle = fopen( $local, $offset > 0 ? 'ab' : 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming into the work directory.
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not write the local file.' );
		}

		while ( $offset < $size && microtime( true ) < $deadline ) {
			$end = min( $size, $offset + self::CHUNK ) - 1;
			$res = $this->content( 'files/download', [ 'path' => $this->remote( $name ) ], '', [ 'Range' => 'bytes=' . $offset . '-' . $end ] );

			if ( ! in_array( $res['code'], [ 200, 206 ], true ) || '' === $res['body'] ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming into the work directory.
				throw new \RuntimeException( esc_html( 'Dropbox: ' . $res['body'] ) );
			}

			fwrite( $handle, $res['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming into the work directory.
			$offset += strlen( $res['body'] );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming into the work directory.

		return [
			'offset' => $offset,
			'size'   => $size,
			'done'   => $offset >= $size,
		];
	}

	public function list_names(): array {
		$names = [];

		try {
			$data = $this->rpc( 'files/list_folder', [ 'path' => $this->folder() ] );
		} catch ( \RuntimeException $e ) {
			if ( str_contains( Util::message( $e ), 'not_found' ) ) {
				return [];
			}
			throw $e;
		}

		$guard = 0;
		while ( true ) {
			foreach ( (array) ( $data['entries'] ?? [] ) as $entry ) {
				if ( 'file' === ( $entry['.tag'] ?? '' ) && Util::is_safe_filename( (string) $entry['name'] ) ) {
					$names[] = (string) $entry['name'];
				}
			}
			if ( empty( $data['has_more'] ) || ++$guard > 100 ) {
				break;
			}
			$data = $this->rpc( 'files/list_folder/continue', [ 'cursor' => (string) $data['cursor'] ] );
		}

		return $names;
	}

	public function get_contents( string $name ): string {
		$res = $this->content( 'files/download', [ 'path' => $this->remote( $name ) ], '' );
		return 200 === $res['code'] ? $res['body'] : '';
	}

	public function delete( string $name ): bool {
		try {
			$this->rpc( 'files/delete_v2', [ 'path' => $this->remote( $name ) ] );
			return true;
		} catch ( \RuntimeException $e ) {
			return str_contains( Util::message( $e ), 'not_found' );
		}
	}
}
