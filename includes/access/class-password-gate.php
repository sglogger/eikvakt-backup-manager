<?php
/**
 * Optional extra password in front of the plugin.
 *
 * Administrator rights already guard every screen. The password is a second
 * barrier for sites with several administrators, or for a hijacked admin
 * session: whoever holds it can download a complete copy of the site, so it
 * deserves its own lock.
 *
 * The password is stored as a password_hash() hash. Unlocking sets a cookie
 * bound to the user, the user's WordPress session token and an expiry, signed
 * with an HMAC over the site's salts; logging out or the timeout locks the
 * plugin again. Five wrong attempts lock the form for fifteen minutes.
 *
 * Locked out? Add `define( 'EKBM_DISABLE_PASSWORD', true );` to wp-config.php,
 * reset the password on the Security tab, and remove the line again.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Password_Gate {

	public const COOKIE = 'ekbm_unlock';

	private const MAX_ATTEMPTS = 5;
	private const LOCKOUT      = 15 * MINUTE_IN_SECONDS;

	public function register(): void {
		add_action( 'admin_init', [ $this, 'handle_unlock' ], 1 );
		add_action( 'wp_logout', [ self::class, 'lock' ] );
	}

	public static function is_enabled(): bool {
		if ( defined( 'EKBM_DISABLE_PASSWORD' ) && EKBM_DISABLE_PASSWORD ) {
			return false;
		}
		$security = Settings::get( Installer::OPTION_SECURITY );
		return ! empty( $security['password_enabled'] ) && '' !== (string) $security['password_hash'];
	}

	/**
	 * Is the current user past the gate?
	 */
	public static function is_unlocked(): bool {
		if ( ! self::is_enabled() ) {
			return true;
		}

		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ) : '';
		$parts  = explode( '|', $cookie );

		if ( 2 !== count( $parts ) || (int) $parts[0] < time() ) {
			return false;
		}

		return hash_equals( self::signature( (int) $parts[0] ), $parts[1] );
	}

	private static function signature( int $expires ): string {
		return hash_hmac( 'sha256', get_current_user_id() . '|' . wp_get_session_token() . '|' . $expires, wp_salt( 'secure_auth' ) . 'ekbm-gate' );
	}

	public static function unlock(): void {
		$minutes = max( 5, min( 720, (int) ( Settings::get( Installer::OPTION_SECURITY )['session_minutes'] ?? 30 ) ) );
		$expires = time() + $minutes * MINUTE_IN_SECONDS;

		setcookie(
			self::COOKIE,
			$expires . '|' . self::signature( $expires ),
			[
				'expires'  => 0,
				'path'     => ADMIN_COOKIE_PATH,
				'domain'   => (string) COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			]
		);
		$_COOKIE[ self::COOKIE ] = $expires . '|' . self::signature( $expires );
	}

	public static function lock(): void {
		setcookie(
			self::COOKIE,
			'',
			[
				'expires'  => time() - YEAR_IN_SECONDS,
				'path'     => ADMIN_COOKIE_PATH,
				'domain'   => (string) COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			]
		);
		unset( $_COOKIE[ self::COOKIE ] );
	}

	public static function set_password( string $password ): void {
		$security                     = Settings::get( Installer::OPTION_SECURITY );
		$security['password_hash']    = password_hash( $password, PASSWORD_DEFAULT );
		$security['password_enabled'] = true;
		Settings::update( Installer::OPTION_SECURITY, $security );
	}

	public static function verify( string $password ): bool {
		$hash = (string) ( Settings::get( Installer::OPTION_SECURITY )['password_hash'] ?? '' );
		return '' !== $hash && password_verify( $password, $hash );
	}

	private static function attempts_key(): string {
		return 'ekbm_gate_' . get_current_user_id();
	}

	public static function is_locked_out(): bool {
		return (int) get_transient( self::attempts_key() ) >= self::MAX_ATTEMPTS;
	}

	/**
	 * Handle the unlock form, which is rendered in place of any plugin screen
	 * while the gate is closed.
	 */
	public function handle_unlock(): void {
		if ( empty( $_POST['ekbm_unlock'] ) ) {
			return;
		}

		if ( ! current_user_can( Admin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'eikvakt-backup-manager' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'ekbm_unlock' );

		// Back to the screen that asked for the password. wp_get_referer()
		// returns false when the referer equals the current URL, which is
		// exactly the case here, so the raw referer is validated instead.
		$redirect = wp_validate_redirect( (string) wp_get_raw_referer(), admin_url( 'admin.php?page=' . Admin::MENU_DASHBOARD ) );

		if ( self::is_locked_out() ) {
			wp_safe_redirect( add_query_arg( 'ekbm_notice', 'gate_locked', $redirect ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a password is compared as entered; sanitising would alter it.
		$password = isset( $_POST['ekbm_password'] ) ? (string) wp_unslash( $_POST['ekbm_password'] ) : '';

		if ( self::verify( $password ) ) {
			delete_transient( self::attempts_key() );
			self::unlock();
			wp_safe_redirect( remove_query_arg( 'ekbm_notice', $redirect ) );
			exit;
		}

		set_transient( self::attempts_key(), (int) get_transient( self::attempts_key() ) + 1, self::LOCKOUT );
		wp_safe_redirect( add_query_arg( 'ekbm_notice', 'gate_wrong', $redirect ) );
		exit;
	}

	/**
	 * Unlock form markup.
	 */
	public static function render_form(): void {
		echo '<div class="wrap ekbm-wrap">';
		echo '<h1>' . esc_html__( 'Backup Manager is locked', 'eikvakt-backup-manager' ) . '</h1>';
		Admin::render_notice();
		echo '<div class="ekbm-card ekbm-gate">';
		echo '<p>' . esc_html__( 'Backups contain a complete copy of this site. Enter the Backup Manager password to continue.', 'eikvakt-backup-manager' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'ekbm_unlock' );
		echo '<input type="hidden" name="ekbm_unlock" value="1">';
		echo '<p><label for="ekbm_password">' . esc_html__( 'Password', 'eikvakt-backup-manager' ) . '</label><br>';
		echo '<input type="password" id="ekbm_password" name="ekbm_password" class="regular-text" autocomplete="current-password" required autofocus></p>';
		submit_button( __( 'Unlock', 'eikvakt-backup-manager' ) );
		echo '</form></div></div>';
	}
}
