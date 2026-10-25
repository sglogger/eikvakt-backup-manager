<?php
/**
 * Microsoft OneDrive (personal and work/school), via Microsoft Graph and the
 * administrator's own Entra ID (Azure AD) app registration.
 *
 * Files go to the app's own folder (Apps/<app name>) — the app is granted
 * `Files.ReadWrite.AppFolder` only and cannot see anything else in the
 * drive. Uploads use upload sessions in 10 MiB chunks (a multiple of the
 * 320 KiB Graph requires); downloads use range requests against the
 * pre-authenticated download URL.
 *
 * External service: https://login.microsoftonline.com and
 * https://graph.microsoft.com — contacted only for a destination the
 * administrator has configured and connected. See readme.txt
 * "External services".
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Storage_Onedrive implements Storage_Driver {

	private const GRAPH = 'https://graph.microsoft.com/v1.0/me/drive/special/approot';
	private const CHUNK = 32 * 327680;
	private const SCOPE = 'offline_access Files.ReadWrite.AppFolder';

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

	/**
	 * @param array<string, mixed> $config Destination config.
	 */
	private static function tenant( array $config ): string {
		$tenant = (string) ( $config['tenant'] ?? 'common' );
		return 1 === preg_match( '/^[a-z0-9.-]{1,64}$/i', $tenant ) ? $tenant : 'common';
	}

	/**
	 * @param array<string, mixed> $config Destination config.
	 */
	public static function authorize_url( array $config, string $challenge, string $state ): string {
		return add_query_arg(
			[
				'client_id'             => rawurlencode( (string) ( $config['client_id'] ?? '' ) ),
				'response_type'         => 'code',
				'redirect_uri'          => rawurlencode( OAuth::redirect_uri() ),
				'response_mode'         => 'query',
				'scope'                 => rawurlencode( self::SCOPE ),
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'state'                 => $state,
			],
			'https://login.microsoftonline.com/' . self::tenant( $config ) . '/oauth2/v2.0/authorize'
		);
	}

	/**
	 * @param array<string, mixed> $config Destination config with secrets opened.
	 * @param array<string, string> $grant  Grant-specific fields.
	 * @return array<string, mixed>
	 */
	private static function token_request( array $config, array $grant ): array {
		$body = array_merge(
			[
				'client_id' => (string) ( $config['client_id'] ?? '' ),
				'scope'     => self::SCOPE,
			],
			$grant
		);
		if ( ! empty( $config['client_secret'] ) ) {
			$body['client_secret'] = (string) $config['client_secret'];
		}

		$response = OAuth::http(
			'https://login.microsoftonline.com/' . self::tenant( $config ) . '/oauth2/v2.0/token',
			[
				'method' => 'POST',
				'body'   => $body,
			]
		);

		$data = json_decode( $response['body'], true );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * @param array<string, mixed> $config Destination config with secrets opened.
	 * @return string Refresh token.
	 */
	public static function exchange_code( array $config, string $code, string $verifier ): string {
		$data = self::token_request(
			$config,
			[
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => OAuth::redirect_uri(),
				'code_verifier' => $verifier,
			]
		);

		if ( empty( $data['refresh_token'] ) ) {
			throw new \RuntimeException( 'Microsoft did not issue a refresh token.' );
		}

		return (string) $data['refresh_token'];
	}

	private function token(): string {
		if ( empty( $this->config['refresh_token'] ) ) {
			throw new \RuntimeException( esc_html__( 'This OneDrive destination is not connected yet. Use "Connect" on the Storage tab.', 'eikvakt-backup-manager' ) );
		}

		return OAuth::access_token(
			$this->id,
			function (): array {
				$data = self::token_request(
					$this->config,
					[
						'grant_type'    => 'refresh_token',
						'refresh_token' => (string) $this->config['refresh_token'],
					]
				);

				if ( empty( $data['access_token'] ) ) {
					throw new \RuntimeException( esc_html__( 'Microsoft refused to renew access. Reconnect the destination.', 'eikvakt-backup-manager' ) );
				}

				// Microsoft rotates refresh tokens; keep the newest one.
				if ( ! empty( $data['refresh_token'] ) && $data['refresh_token'] !== $this->config['refresh_token'] ) {
					$destination = Destinations::get( $this->id );
					if ( null !== $destination ) {
						$destination['config']['refresh_token'] = Secret_Cipher::encrypt( (string) $data['refresh_token'] );
						Destinations::save( $destination );
					}
				}

				return [ (string) $data['access_token'], (int) ( $data['expires_in'] ?? 3600 ) ];
			}
		);
	}

	private function folder(): string {
		$path = trim( (string) ( $this->config['path'] ?? '' ), '/' );
		return implode( '/', array_map( 'rawurlencode', array_filter( explode( '/', $path ) ) ) );
	}

	/**
	 * Graph item address of a file (or of the folder when $name is '').
	 */
	private function item( string $name = '' ): string {
		if ( '' !== $name && ! Util::is_safe_filename( $name ) ) {
			throw new \RuntimeException( 'Invalid file name.' );
		}

		$path = trim( $this->folder() . '/' . $name, '/' );
		return '' === $path ? self::GRAPH : self::GRAPH . ':/' . $path . ':';
	}

	/**
	 * @param array<string, mixed> $args Request arguments.
	 * @return array{code:int, body:string, headers:array<string, string>}
	 */
	private function graph( string $method, string $url, array $args = [] ): array {
		$args['method']                   = $method;
		$args['headers']                  = (array) ( $args['headers'] ?? [] );
		$args['headers']['Authorization'] = 'Bearer ' . $this->token();
		$args['headers']['Accept']        = 'application/json';
		return OAuth::http( $url, $args );
	}

	public function test() {
		try {
			$name = 'ekbm-test-' . Util::random_token( 4 ) . '.txt';
			$res  = $this->graph(
				'PUT',
				$this->item( $name ) . '/content',
				[
					'headers' => [ 'Content-Type' => 'text/plain' ],
					'body'    => 'ok',
				]
			);
			if ( ! in_array( $res['code'], [ 200, 201 ], true ) ) {
				return new \WP_Error( 'ekbm_onedrive', 'OneDrive: ' . $res['body'] );
			}
			$this->delete( $name );
			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'ekbm_onedrive', Util::message( $e ) );
		}
	}

	public function upload( string $local, string $name, array $state, float $deadline ): array {
		$size   = (int) filesize( $local );
		$offset = (int) ( $state['offset'] ?? 0 );
		$url    = (string) ( $state['url'] ?? '' );

		if ( 0 === $size ) {
			$res = $this->graph( 'PUT', $this->item( $name ) . '/content', [ 'body' => '' ] );
			if ( ! in_array( $res['code'], [ 200, 201 ], true ) ) {
				throw new \RuntimeException( esc_html( 'OneDrive: ' . $res['body'] ) );
			}
			return [ 'done' => true ];
		}

		if ( '' === $url ) {
			$res = $this->graph(
				'POST',
				$this->item( $name ) . '/createUploadSession',
				[
					'headers' => [ 'Content-Type' => 'application/json' ],
					'body'    => (string) wp_json_encode( [ 'item' => [ '@microsoft.graph.conflictBehavior' => 'replace' ] ] ),
				]
			);
			$url = (string) ( json_decode( $res['body'], true )['uploadUrl'] ?? '' );
			if ( '' === $url ) {
				throw new \RuntimeException( esc_html( 'OneDrive: ' . $res['body'] ) );
			}
			$offset = 0;
		}

		$handle = fopen( $local, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a local archive.
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not open the local file.' );
		}
		fseek( $handle, $offset );

		do {
			$chunk = (string) fread( $handle, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streaming a local archive.
			$end   = $offset + strlen( $chunk ) - 1;

			// The upload URL is pre-authenticated; Graph rejects an
			// Authorization header on it.
			$res = OAuth::http(
				$url,
				[
					'method'  => 'PUT',
					'timeout' => 120,
					'headers' => [
						'Content-Length' => (string) strlen( $chunk ),
						'Content-Range'  => 'bytes ' . $offset . '-' . $end . '/' . $size,
					],
					'body'    => $chunk,
				]
			);

			if ( ! in_array( $res['code'], [ 200, 201, 202 ], true ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a local archive.
				// A lost session cannot be resumed; start a fresh one next step.
				if ( 404 === $res['code'] ) {
					return [ 'done' => false ];
				}
				throw new \RuntimeException( esc_html( 'OneDrive: ' . $res['body'] ) );
			}

			$offset = $end + 1;

			if ( in_array( $res['code'], [ 200, 201 ], true ) || $offset >= $size ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a local archive.
				return [ 'done' => true ];
			}
		} while ( microtime( true ) < $deadline );

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a local archive.

		return [
			'url'    => $url,
			'offset' => $offset,
			'done'   => false,
		];
	}

	public function download( string $name, string $local, array $state, float $deadline ): array {
		$offset = (int) ( $state['offset'] ?? 0 );
		$size   = (int) ( $state['size'] ?? -1 );
		$url    = (string) ( $state['url'] ?? '' );

		if ( $size < 0 || '' === $url ) {
			$res  = $this->graph( 'GET', $this->item( $name ) . '?select=size,@microsoft.graph.downloadUrl' );
			$meta = json_decode( $res['body'], true );
			$size = (int) ( $meta['size'] ?? 0 );
			$url  = (string) ( $meta['@microsoft.graph.downloadUrl'] ?? '' );
			if ( '' === $url ) {
				throw new \RuntimeException( esc_html( 'OneDrive: ' . $res['body'] ) );
			}
		}

		$handle = fopen( $local, $offset > 0 ? 'ab' : 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming into the work directory.
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not write the local file.' );
		}

		while ( $offset < $size && microtime( true ) < $deadline ) {
			$end = min( $size, $offset + self::CHUNK ) - 1;
			$res = OAuth::http(
				$url,
				[
					'method'  => 'GET',
					'timeout' => 120,
					'headers' => [ 'Range' => 'bytes=' . $offset . '-' . $end ],
				]
			);

			if ( ! in_array( $res['code'], [ 200, 206 ], true ) || '' === $res['body'] ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming into the work directory.
				// The download URL is short-lived; fetch a new one next step.
				return [
					'offset' => $offset,
					'size'   => $size,
					'done'   => false,
				];
			}

			fwrite( $handle, $res['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming into the work directory.
			$offset += strlen( $res['body'] );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming into the work directory.

		return [
			'offset' => $offset,
			'size'   => $size,
			'url'    => $url,
			'done'   => $offset >= $size,
		];
	}

	public function list_names(): array {
		$names = [];
		$url   = ( '' === $this->folder() ? self::GRAPH : $this->item() ) . '/children?$select=name,file&$top=200';
		$guard = 0;

		while ( '' !== $url && $guard++ < 100 ) {
			$res = $this->graph( 'GET', $url );
			if ( 404 === $res['code'] ) {
				return [];
			}
			$data = json_decode( $res['body'], true );
			foreach ( (array) ( $data['value'] ?? [] ) as $entry ) {
				if ( isset( $entry['file'] ) && Util::is_safe_filename( (string) $entry['name'] ) ) {
					$names[] = (string) $entry['name'];
				}
			}
			$url = (string) ( $data['@odata.nextLink'] ?? '' );
		}

		return $names;
	}

	public function get_contents( string $name ): string {
		$res = $this->graph( 'GET', $this->item( $name ) . '/content' );
		return 200 === $res['code'] ? $res['body'] : '';
	}

	public function delete( string $name ): bool {
		$res = $this->graph( 'DELETE', $this->item( $name ) );
		return in_array( $res['code'], [ 204, 404 ], true );
	}
}
