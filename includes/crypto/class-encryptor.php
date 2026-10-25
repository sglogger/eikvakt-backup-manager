<?php
/**
 * Streaming, resumable file encryption for backup archives.
 *
 * Format (all integers big-endian):
 *
 *   header  "EKBMENC1" | kdf:u8 | salt:16 | iterations:u32 | reserved:u32
 *   chunk   final:u8 | nonce:24 | length:u32 | ciphertext (≤ 1 MiB + 16-byte tag)
 *
 * Every chunk is sealed with XChaCha20-Poly1305 (libsodium; WordPress ships
 * sodium_compat, so this works on every host WordPress itself runs on) under
 * a key derived from the passphrase with PBKDF2-HMAC-SHA256. The additional
 * data of each chunk binds the header, the chunk's position and its "final"
 * flag, so chunks cannot be reordered, dropped, duplicated or moved between
 * files, and a truncated file is detected because its last chunk is not
 * marked final.
 *
 * Chunks are independent, which is what makes the operation resumable:
 * a step only needs the byte offsets, never a cipher state that would have to
 * be written to disk between requests.
 *
 * Free of WordPress: unit-tested on its own.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Encryptor {

	public const MAGIC      = 'EKBMENC1';
	public const EXTENSION  = '.enc';
	public const KDF_PBKDF2 = 2;

	/** OWASP 2023 recommendation for PBKDF2-HMAC-SHA256. */
	public const ITERATIONS = 600000;

	/**
	 * Highest iteration count accepted from a file header. The count is read
	 * from the (possibly manipulated) file itself; without a ceiling a
	 * crafted header could make one request spend hours in PBKDF2.
	 */
	public const MAX_ITERATIONS = 5000000;

	private const CHUNK      = 1048576;
	private const HEADER_LEN = 8 + 1 + 16 + 4 + 4;
	private const NONCE_LEN  = 24;
	private const KEY_LEN    = 32;
	private const TAG_LEN    = 16;
	private const CHUNK_HEAD = 1 + 24 + 4;
	private const MAX_CT_LEN = self::CHUNK + self::TAG_LEN;

	/** @var array<string, string> Derived keys by salt, for this request only. */
	private static array $keys = [];

	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) && function_exists( 'hash_pbkdf2' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function start_encrypt( string $source, string $target ): array {
		return [
			'source' => $source,
			'target' => $target,
			'in'     => 0,
			'chunk'  => 0,
			'header' => '',
			'done'   => false,
		];
	}

	/**
	 * Encrypt until the deadline. Throws on I/O failure.
	 *
	 * @param array<string, mixed> $state State from start_encrypt() or a previous step.
	 * @return array<string, mixed>
	 */
	public static function encrypt_step( array $state, string $passphrase, float $deadline, int $iterations = self::ITERATIONS ): array {
		$size = (int) filesize( (string) $state['source'] );

		// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming binary archives in the protected backup directory.
		if ( '' === $state['header'] ) {
			$salt   = random_bytes( 16 );
			$header = self::MAGIC . chr( self::KDF_PBKDF2 ) . $salt . pack( 'N', $iterations ) . pack( 'N', 0 );

			file_put_contents( (string) $state['target'], $header );
			$state['header'] = bin2hex( $header );
		}

		$header = (string) hex2bin( (string) $state['header'] );
		$key    = self::key_for_header( $header, $passphrase );

		$in  = fopen( (string) $state['source'], 'rb' );
		$out = fopen( (string) $state['target'], 'ab' );
		if ( false === $in || false === $out ) {
			throw new \RuntimeException( 'encrypt-io' );
		}

		fseek( $in, (int) $state['in'] );

		do {
			$plain = (string) fread( $in, self::CHUNK );
			$pos   = (int) $state['in'] + strlen( $plain );
			$final = $pos >= $size;

			$nonce = random_bytes( self::NONCE_LEN );
			$ad    = self::additional_data( $header, (int) $state['chunk'], $final );
			$ct    = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain, $ad, $nonce, $key );

			fwrite( $out, chr( $final ? 1 : 0 ) . $nonce . pack( 'N', strlen( $ct ) ) . $ct );

			$state['in'] = $pos;
			++$state['chunk'];

			if ( $final ) {
				$state['done'] = true;
				break;
			}
		} while ( microtime( true ) < $deadline );

		fclose( $in );
		fclose( $out );
		// phpcs:enable

		return $state;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function start_decrypt( string $source, string $target ): array {
		return [
			'source' => $source,
			'target' => $target,
			'in'     => self::HEADER_LEN,
			'chunk'  => 0,
			'done'   => false,
		];
	}

	/**
	 * Decrypt until the deadline. Throws \RuntimeException('decrypt-auth') on
	 * a wrong passphrase or tampered data, ('decrypt-truncated') on a file that
	 * ends without its final chunk.
	 *
	 * @param array<string, mixed> $state State from start_decrypt() or a previous step.
	 * @return array<string, mixed>
	 */
	public static function decrypt_step( array $state, string $passphrase, float $deadline ): array {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming binary archives in the protected work directory.
		$in = fopen( (string) $state['source'], 'rb' );
		if ( false === $in ) {
			throw new \RuntimeException( 'decrypt-io' );
		}

		$header = (string) fread( $in, self::HEADER_LEN );
		if ( strlen( $header ) !== self::HEADER_LEN || ! str_starts_with( $header, self::MAGIC ) ) {
			fclose( $in );
			throw new \RuntimeException( 'decrypt-format' );
		}

		$key = self::key_for_header( $header, $passphrase );
		$out = fopen( (string) $state['target'], 0 === (int) $state['chunk'] ? 'wb' : 'ab' );
		if ( false === $out ) {
			fclose( $in );
			throw new \RuntimeException( 'decrypt-io' );
		}

		fseek( $in, (int) $state['in'] );

		do {
			$head = (string) fread( $in, self::CHUNK_HEAD );
			if ( strlen( $head ) < self::CHUNK_HEAD ) {
				fclose( $in );
				fclose( $out );
				throw new \RuntimeException( 'decrypt-truncated' );
			}

			$final  = 1 === ord( $head[0] );
			$nonce  = substr( $head, 1, self::NONCE_LEN );
			$length = (int) unpack( 'N', substr( $head, 1 + self::NONCE_LEN, 4 ) )[1];

			if ( $length < self::TAG_LEN || $length > self::MAX_CT_LEN ) {
				fclose( $in );
				fclose( $out );
				throw new \RuntimeException( 'decrypt-format' );
			}

			$ct    = (string) fread( $in, $length );
			$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $ct, self::additional_data( $header, (int) $state['chunk'], $final ), $nonce, $key );

			if ( false === $plain ) {
				fclose( $in );
				fclose( $out );
				throw new \RuntimeException( 'decrypt-auth' );
			}

			fwrite( $out, $plain );

			$state['in'] = (int) $state['in'] + self::CHUNK_HEAD + $length;
			++$state['chunk'];

			if ( $final ) {
				$state['done'] = true;
				break;
			}
		} while ( microtime( true ) < $deadline );

		fclose( $in );
		fclose( $out );
		// phpcs:enable

		return $state;
	}

	/**
	 * Cheap passphrase check: decrypt only the first chunk.
	 */
	public static function verify( string $file, string $passphrase ): bool {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- reads the first chunk of a local archive.
		$in = fopen( $file, 'rb' );
		if ( false === $in ) {
			return false;
		}

		$header = (string) fread( $in, self::HEADER_LEN );
		$head   = (string) fread( $in, self::CHUNK_HEAD );
		if ( strlen( $header ) !== self::HEADER_LEN || strlen( $head ) !== self::CHUNK_HEAD || ! str_starts_with( $header, self::MAGIC ) ) {
			fclose( $in );
			return false;
		}

		$final  = 1 === ord( $head[0] );
		$length = (int) unpack( 'N', substr( $head, 1 + self::NONCE_LEN, 4 ) )[1];
		$ct     = $length <= self::MAX_CT_LEN ? (string) fread( $in, $length ) : '';
		fclose( $in );
		// phpcs:enable

		try {
			$key = self::key_for_header( $header, $passphrase );
		} catch ( \RuntimeException $e ) {
			return false;
		}

		$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
			$ct,
			self::additional_data( $header, 0, $final ),
			substr( $head, 1, self::NONCE_LEN ),
			$key
		);

		return false !== $plain;
	}

	public static function is_encrypted_file( string $file ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the 8-byte magic of a local file.
		$magic = (string) file_get_contents( $file, false, null, 0, strlen( self::MAGIC ) );
		return self::MAGIC === $magic;
	}

	private static function additional_data( string $header, int $chunk, bool $final ): string {
		return hash( 'sha256', $header, true ) . pack( 'J', $chunk ) . chr( $final ? 1 : 0 );
	}

	private static function key_for_header( string $header, string $passphrase ): string {
		$kdf        = ord( $header[8] );
		$salt       = substr( $header, 9, 16 );
		$iterations = (int) unpack( 'N', substr( $header, 25, 4 ) )[1];

		if ( self::KDF_PBKDF2 !== $kdf || $iterations < 1000 || $iterations > self::MAX_ITERATIONS ) {
			throw new \RuntimeException( 'decrypt-format' );
		}

		$cache = hash( 'sha256', $salt . $iterations . $passphrase );
		if ( ! isset( self::$keys[ $cache ] ) ) {
			self::$keys[ $cache ] = hash_pbkdf2( 'sha256', $passphrase, $salt, $iterations, self::KEY_LEN, true );
		}

		return self::$keys[ $cache ];
	}
}
