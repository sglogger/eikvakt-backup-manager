<?php
/**
 * Persistence for the running job and the queue.
 *
 * Job state lives in files in the protected work directory, not in the
 * database. A restore replaces the database while it runs; state kept in
 * wp_options would be overwritten by the backup's copy half-way through and
 * the restore would lose track of itself.
 *
 * Mutual exclusion uses flock(): the lock belongs to the PHP process and is
 * released by the operating system when the process ends, so a request killed
 * by the host can never leave a stale lock behind.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Job_Store {

	/** @var resource|null */
	private static $lock = null;

	private static function path( string $name ): string {
		return Paths::work_dir() . '/' . $name;
	}

	/**
	 * Take the runner lock without waiting.
	 */
	public static function lock(): bool {
		if ( null !== self::$lock ) {
			return true;
		}

		Paths::ensure();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- lock file; flock() needs a real handle.
		$handle = fopen( self::path( 'runner.lock' ), 'c' );
		if ( false === $handle ) {
			return false;
		}

		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- lock file.
			return false;
		}

		self::$lock = $handle;
		return true;
	}

	public static function unlock(): void {
		if ( null === self::$lock ) {
			return;
		}
		flock( self::$lock, LOCK_UN );
		fclose( self::$lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- lock file.
		self::$lock = null;
	}

	/**
	 * Is another process running a step right now?
	 */
	public static function is_busy(): bool {
		if ( null !== self::$lock ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- lock probe.
		$handle = @fopen( self::path( 'runner.lock' ), 'c' );
		if ( false === $handle ) {
			return false;
		}

		$free = flock( $handle, LOCK_EX | LOCK_NB );
		if ( $free ) {
			flock( $handle, LOCK_UN );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- lock probe.

		return ! $free;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		return self::read( 'current.json' );
	}

	/**
	 * @param array<string, mixed>|null $job Job, or null to clear.
	 */
	public static function save_current( ?array $job ): void {
		if ( null === $job ) {
			wp_delete_file( self::path( 'current.json' ) );
			return;
		}
		$job['updated'] = time();
		self::write( 'current.json', $job );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function queue(): array {
		return array_values( (array) ( self::read( 'queue.json' ) ?? [] ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $queue Queue.
	 */
	public static function save_queue( array $queue ): void {
		self::write( 'queue.json', array_values( $queue ) );
	}

	/**
	 * Last finished job, for the dashboard to show the outcome after the
	 * progress bar disappears.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last_finished(): ?array {
		return self::read( 'last.json' );
	}

	/**
	 * @param array<string, mixed> $job Finished job.
	 */
	public static function save_last_finished( array $job ): void {
		unset( $job['state'], $job['log_buffer'] );
		self::write( 'last.json', $job );
	}

	/**
	 * Shared secret for loopback requests, created on first use.
	 */
	public static function runner_key(): string {
		$path = self::path( 'runner.key' );

		if ( is_readable( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local secret in the protected work directory.
			$key = trim( (string) file_get_contents( $path ) );
			if ( strlen( $key ) >= 32 ) {
				return $key;
			}
		}

		Paths::ensure();
		$key = Util::random_token( 32 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local secret in the protected work directory.
		file_put_contents( $path, $key, LOCK_EX );

		return $key;
	}

	/**
	 * Directory for one job's temporary files.
	 */
	public static function job_dir( string $job_id ): string {
		$dir = Paths::work_dir() . '/job-' . preg_replace( '/[^a-z0-9]/', '', $job_id );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * @return array<mixed>|null
	 */
	private static function read( string $name ): ?array {
		$path = self::path( $name );
		if ( ! is_readable( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- job state in the protected work directory.
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Write atomically: a request killed mid-write must never leave a
	 * half-written state file that loses the job.
	 *
	 * @param array<mixed> $data Data.
	 */
	private static function write( string $name, array $data ): void {
		Paths::ensure();
		$path = self::path( $name );
		$tmp  = $path . '.' . Util::random_token( 4 ) . '.tmp';

		// phpcs:disable WordPress.WP.AlternativeFunctions -- atomic state write in the protected work directory.
		if ( false !== file_put_contents( $tmp, (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) ) ) {
			rename( $tmp, $path );
		}
		// phpcs:enable
	}
}
