<?php
declare( strict_types = 1 );

namespace EikvaktBackupManager\Tests\Unit;

use EikvaktBackupManager\Encryptor;
use PHPUnit\Framework\TestCase;

final class EncryptorTest extends TestCase {

	private const PASS = 'correct horse battery staple';

	/** Low iteration count: the format, not the KDF cost, is under test. */
	private const ITERATIONS = 1000;

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/ekbm-enc-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->dir . '/*' ) ?: [] );
		rmdir( $this->dir );
	}

	private function encrypt( string $plain, float $deadline = INF ): string {
		file_put_contents( $this->dir . '/plain', $plain );
		$state = Encryptor::start_encrypt( $this->dir . '/plain', $this->dir . '/enc' );
		do {
			$state = Encryptor::encrypt_step( $state, self::PASS, $deadline, self::ITERATIONS );
		} while ( ! $state['done'] );
		return $this->dir . '/enc';
	}

	private function decrypt( string $file, string $pass = self::PASS ): string {
		$state = Encryptor::start_decrypt( $file, $this->dir . '/out' );
		do {
			$state = Encryptor::decrypt_step( $state, $pass, INF );
		} while ( ! $state['done'] );
		return (string) file_get_contents( $this->dir . '/out' );
	}

	public function test_round_trip_across_chunks_and_resumed_steps(): void {
		$plain = random_bytes( 2 * 1048576 + 123 );
		// A deadline in the past forces one chunk per step: resumability.
		$file = $this->encrypt( $plain, 0.0 );

		$this->assertTrue( Encryptor::is_encrypted_file( $file ) );
		$this->assertTrue( Encryptor::verify( $file, self::PASS ) );
		$this->assertSame( $plain, $this->decrypt( $file ) );
	}

	public function test_empty_file(): void {
		$this->assertSame( '', $this->decrypt( $this->encrypt( '' ) ) );
	}

	public function test_wrong_passphrase_is_rejected(): void {
		$file = $this->encrypt( 'secret data' );
		$this->assertFalse( Encryptor::verify( $file, 'wrong' ) );

		$this->expectExceptionMessage( 'decrypt-auth' );
		$this->decrypt( $file, 'wrong' );
	}

	public function test_excessive_iteration_count_in_header_is_rejected_before_deriving(): void {
		$file  = $this->encrypt( 'data' );
		$bytes = file_get_contents( $file );
		// Iterations live at offset 25 (u32, big-endian).
		$bytes = substr_replace( $bytes, pack( 'N', 0xFFFFFFFF ), 25, 4 );
		file_put_contents( $file, $bytes );

		$start = microtime( true );
		$this->assertFalse( Encryptor::verify( $file, self::PASS ) );
		$this->assertLessThan( 1.0, microtime( true ) - $start, 'must not run PBKDF2 with the forged count' );
	}

	public function test_tampering_is_detected(): void {
		$file                           = $this->encrypt( str_repeat( 'A', 5000 ) );
		$bytes                          = file_get_contents( $file );
		$bytes[ strlen( $bytes ) - 10 ] = chr( ord( $bytes[ strlen( $bytes ) - 10 ] ) ^ 1 );
		file_put_contents( $file, $bytes );

		$this->expectExceptionMessage( 'decrypt-auth' );
		$this->decrypt( $file );
	}

	public function test_truncation_is_detected(): void {
		$file  = $this->encrypt( random_bytes( 1048576 + 10 ) );
		$bytes = file_get_contents( $file );
		// Drop the final chunk entirely.
		file_put_contents( $file, substr( $bytes, 0, 37 + 29 + 1048576 + 16 ) );

		$this->expectExceptionMessage( 'decrypt-truncated' );
		$this->decrypt( $file );
	}
}
