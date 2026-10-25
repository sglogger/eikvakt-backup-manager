<?php
/**
 * OAuth 2.0 authorisation-code flow with PKCE for Dropbox and OneDrive.
 *
 * There is no relay server: every site registers its own app with the
 * provider (the settings screen walks through it and shows the exact
 * redirect URI to enter). The provider redirects back to admin-post.php on
 * this site, where the code is exchanged for a refresh token that is stored
 * sealed with Secret_Cipher.
 *
 * admin-post.php is used as the redirect URI precisely because it carries no
 * query string: Microsoft refuses redirect URIs with query parameters for
 * personal accounts.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OAuth {

	private const PENDING_TTL = 15 * MINUTE_IN_SECONDS;

	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php' );
	}

	/**
	 * Start the flow for a destination; returns the provider URL to send the
	 * administrator to.
	 */
	public static function begin( string $destination_id ): string {
		$destination = Destinations::get( $destination_id );
		if ( null === $destination ) {
			return '';
		}

		$verifier  = self::base64url( random_bytes( 48 ) );
		$challenge = self::base64url( hash( 'sha256', $verifier, true ) );
		$state     = Util::random_token( 16 );

		$pending           = array_filter(
			(array) get_option( Installer::OPTION_OAUTH_PENDING, [] ),
			static fn( $p ) => (int) ( $p['expires'] ?? 0 ) > time()
		);
		$pending[ $state ] = [
			'destination' => $destination_id,
			'verifier'    => Secret_Cipher::encrypt( $verifier ),
			'user'        => get_current_user_id(),
			'expires'     => time() + self::PENDING_TTL,
		];
		update_option( Installer::OPTION_OAUTH_PENDING, $pending, false );

		$config = (array) $destination['config'];

		switch ( $destination['type'] ) {
			case 'dropbox':
				return Storage_Dropbox::authorize_url( $config, $challenge, $state );
			case 'onedrive':
				return Storage_Onedrive::authorize_url( $config, $challenge, $state );
		}

		return '';
	}

	/**
	 * Start the no-redirect flow (Dropbox with the plugin's app): the
	 * administrator opens the URL, presses "Allow" and pastes the code shown
	 * by Dropbox into the plugin.
	 *
	 * @return array{state:string, url:string}|null
	 */
	public static function begin_paste( string $destination_id ): ?array {
		$url = self::begin( $destination_id );
		if ( '' === $url ) {
			return null;
		}

		// begin() stored exactly one new pending entry for this user; it is
		// the newest one.
		$pending = (array) get_option( Installer::OPTION_OAUTH_PENDING, [] );
		$state   = (string) array_key_last( $pending );

		$pending[ $state ]['url'] = $url;
		update_option( Installer::OPTION_OAUTH_PENDING, $pending, false );

		return [
			'state' => $state,
			'url'   => $url,
		];
	}

	/**
	 * Authorisation URL of a pending paste flow, for the form.
	 */
	public static function pending_url( string $state ): string {
		if ( ! self::is_pending( $state ) ) {
			return '';
		}
		return (string) ( ( (array) get_option( Installer::OPTION_OAUTH_PENDING, [] ) )[ $state ]['url'] ?? '' );
	}

	/**
	 * Destination id of a pending flow.
	 */
	public static function pending_destination( string $state ): string {
		if ( ! self::is_pending( $state ) ) {
			return '';
		}
		return (string) ( ( (array) get_option( Installer::OPTION_OAUTH_PENDING, [] ) )[ $state ]['destination'] ?? '' );
	}

	/**
	 * `admin_post` (no action): the provider's redirect back to this site.
	 */
	public static function callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the OAuth `state` parameter is the CSRF token here; it is single-use, bound to the user and expires.
		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( (string) $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) : '';
		// phpcs:enable

		if ( '' === $state ) {
			return;
		}

		if ( ! current_user_can( Admin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'eikvakt-backup-manager' ), '', [ 'response' => 403 ] );
		}

		$notice = '' === $error ? self::complete( $state, $code ) : 'oauth_failed';

		wp_safe_redirect(
			add_query_arg(
				[
					'page'        => Admin::MENU_SETTINGS,
					'tab'         => 'storage',
					'ekbm_notice' => $notice,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Is a pending authorisation for this state still usable by the current
	 * user? Used to show the "paste the code" form.
	 */
	public static function is_pending( string $state ): bool {
		$entry = ( (array) get_option( Installer::OPTION_OAUTH_PENDING, [] ) )[ $state ] ?? null;
		return is_array( $entry ) && (int) $entry['expires'] > time() && get_current_user_id() === (int) $entry['user'];
	}

	/**
	 * Exchange an authorisation code for a refresh token and store it.
	 * Shared by the redirect callback and the paste-the-code form. The
	 * pending entry is single-use whatever the outcome.
	 *
	 * @return string Notice key: oauth_ok | oauth_failed.
	 */
	public static function complete( string $state, string $code ): string {
		$pending = (array) get_option( Installer::OPTION_OAUTH_PENDING, [] );
		$entry   = $pending[ $state ] ?? null;
		unset( $pending[ $state ] );
		update_option( Installer::OPTION_OAUTH_PENDING, $pending, false );

		$notice = 'oauth_failed';

		if ( is_array( $entry ) && (int) $entry['expires'] > time() && get_current_user_id() === (int) $entry['user'] && '' !== $code ) {
			$destination = Destinations::get( (string) $entry['destination'] );

			if ( null !== $destination ) {
				try {
					$verifier = Secret_Cipher::decrypt( (string) $entry['verifier'] );
					$config   = Destinations::open_config( $destination );

					$refresh = 'dropbox' === $destination['type']
						? Storage_Dropbox::exchange_code( $config, $code, $verifier )
						: Storage_Onedrive::exchange_code( $config, $code, $verifier );

					$destination['config']['refresh_token'] = Secret_Cipher::encrypt( $refresh );
					Destinations::save( $destination );
					delete_transient( 'ekbm_token_' . $destination['id'] );
					$notice = 'oauth_ok';
				} catch ( \Throwable $e ) {
					$notice = 'oauth_failed';
				}
			}
		}

		return $notice;
	}

	/**
	 * Cached access token for a destination, refreshed when it expires.
	 *
	 * @param callable(): array{0:string, 1:int} $refresh Returns [token, lifetime].
	 */
	public static function access_token( string $destination_id, callable $refresh ): string {
		$cached = get_transient( 'ekbm_token_' . $destination_id );
		if ( is_string( $cached ) && '' !== $cached ) {
			$token = Secret_Cipher::decrypt( $cached );
			if ( '' !== $token ) {
				return $token;
			}
		}

		[ $token, $lifetime ] = $refresh();

		set_transient( 'ekbm_token_' . $destination_id, Secret_Cipher::encrypt( $token ), max( 60, $lifetime - 120 ) );

		return $token;
	}

	/**
	 * HTTP request that throws on transport errors and returns code, body and
	 * headers.
	 *
	 * @param array<string, mixed> $args wp_remote_request() arguments.
	 * @return array{code:int, body:string, headers:array<string, string>}
	 */
	public static function http( string $url, array $args ): array {
		$args = array_merge( [ 'timeout' => 60 ], $args );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$raw = wp_remote_retrieve_headers( $response );
		if ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) {
			$raw = $raw->getAll();
		}

		$headers = [];
		foreach ( (array) $raw as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
		}

		return [
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => $headers,
		];
	}

	public static function base64url( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url is what RFC 7636 (PKCE) specifies.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
