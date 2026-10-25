<?php
/**
 * Authenticated encryption for stored credentials.
 *
 * FTP passwords, SFTP keys, OAuth refresh tokens and the backup passphrase
 * are bearer credentials. Stored in plain text they would turn any SQL
 * injection or stray database dump into access to every off-site copy of the
 * backups, so they are sealed with AES-256-GCM under a key derived from the
 * site's salts — which live in wp-config.php, not in the database.
 *
 * Consequences worth knowing about:
 *
 *   - Rotating SECURE_AUTH_SALT makes every stored credential undecryptable.
 *     Destinations then show "re-enter credentials" instead of failing
 *     silently.
 *   - A database restored onto a different site (migration) carries sealed
 *     values the target cannot open, for the same reason. That is intended:
 *     credentials do not travel with a copy of the database.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Secret_Cipher {

	private const CIPHER = 'aes-256-gcm';

	/** Marks the format, so a future scheme can be told apart from this one. */
	private const PREFIX = 'ekbm1:';

	private const IV_BYTES  = 12;
	private const TAG_BYTES = 16;

	/**
	 * Can secrets be stored safely on this installation?
	 */
	public static function is_available(): bool {
		return function_exists( 'openssl_encrypt' )
			&& in_array( self::CIPHER, (array) openssl_get_cipher_methods(), true );
	}

	/**
	 * @return string The sealed value, or '' when it could not be sealed.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext || ! self::is_available() ) {
			return '';
		}

		$iv  = random_bytes( self::IV_BYTES );
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			self::key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_BYTES
		);

		if ( ! is_string( $ciphertext ) || '' === $ciphertext ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding for binary ciphertext going into a text column, not obfuscation.
		return self::PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * @return string The plaintext, or '' when the value is missing, tampered
	 *                with, or was sealed under different salts.
	 */
	public static function decrypt( string $blob ): string {
		if ( ! str_starts_with( $blob, self::PREFIX ) || ! self::is_available() ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reverses our own storage encoding above; strict mode rejects anything malformed.
		$raw = base64_decode( substr( $blob, strlen( self::PREFIX ) ), true );

		if ( ! is_string( $raw ) || strlen( $raw ) <= self::IV_BYTES + self::TAG_BYTES ) {
			return '';
		}

		$iv         = substr( $raw, 0, self::IV_BYTES );
		$tag        = substr( $raw, self::IV_BYTES, self::TAG_BYTES );
		$ciphertext = substr( $raw, self::IV_BYTES + self::TAG_BYTES );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );

		// GCM authenticates as well as encrypts: a false here means the stored
		// value was altered, not merely that the key is wrong.
		return is_string( $plaintext ) ? $plaintext : '';
	}

	private static function key(): string {
		return hash_hkdf( 'sha256', wp_salt( 'secure_auth' ), 32, 'ekbm-stored-secret' );
	}
}
