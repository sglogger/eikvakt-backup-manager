<?php
/**
 * Runs backup, restore and migration jobs in short, resumable steps.
 *
 * Shared hosting kills PHP requests after 30–60 seconds and WP-Cron only runs
 * when somebody visits the site. So a job never runs in one request. Each
 * step works for the configured time budget, saves its state, and hands over
 * to the next request, which can come from three independent places:
 *
 *   1. a non-blocking loopback request to admin-ajax.php, sent at the end of
 *      every step (fast path);
 *   2. a single WP-Cron event, rescheduled with every step, for hosts that
 *      block loopback requests;
 *   3. the admin screen, whose progress polling also advances the job — so
 *      a backup started by hand finishes even where 1 and 2 both fail.
 *
 * Only one step runs at a time (flock in Job_Store); duplicate kicks are
 * harmless and simply return.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Job_Runner {

	public const STATUS_QUEUED    = 'queued';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_DONE      = 'done';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	/** A step that throws is retried this many times before the job fails. */
	private const MAX_ATTEMPTS = 3;

	private static ?Job_Runner $instance = null;

	public static function instance(): Job_Runner {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_filter( 'cron_schedules', [ Installer::class, 'cron_intervals' ] ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- five minutes, see Installer::cron_intervals().
		add_action( Installer::CRON_JOB_TICK, [ $this, 'cron_tick' ] );
		add_action( 'wp_ajax_ekbm_tick', [ $this, 'loopback_tick' ] );
		add_action( 'wp_ajax_nopriv_ekbm_tick', [ $this, 'loopback_tick' ] );
	}

	/**
	 * @return Job_Handler|null
	 */
	public static function handler( string $type ) {
		$handlers = [
			'backup'  => Backup_Job::class,
			'restore' => Restore_Job::class,
			'pull'    => Pull_Job::class,
		];

		/**
		 * Filters the job handler classes by job type.
		 *
		 * @param array<string, class-string<Job_Handler>> $handlers Handlers.
		 */
		$handlers = (array) apply_filters( 'ekbm_job_handlers', $handlers );

		return isset( $handlers[ $type ] ) ? new $handlers[ $type ]() : null;
	}

	/**
	 * Add a job to the queue and get it going.
	 *
	 * @param array<string, mixed> $params Handler parameters.
	 * @return string Job id.
	 */
	public static function enqueue( string $type, array $params, string $trigger = 'manual' ): string {
		Paths::ensure();

		$job = self::new_job( $type, $params, $trigger );

		$queue   = Job_Store::queue();
		$queue[] = $job;
		Job_Store::save_queue( $queue );

		self::kick();

		return (string) $job['id'];
	}

	/**
	 * @param array<string, mixed> $params Handler parameters.
	 * @return array<string, mixed>
	 */
	private static function new_job( string $type, array $params, string $trigger ): array {
		return [
			'id'         => Util::random_token( 8 ),
			'type'       => $type,
			'trigger'    => $trigger,
			'params'     => $params,
			'status'     => self::STATUS_QUEUED,
			'phase'      => 'queued',
			'progress'   => 0,
			'message'    => '',
			'state'      => [],
			'warnings'   => [],
			'error'      => '',
			'created'    => time(),
			'started'    => 0,
			'updated'    => time(),
			'run_id'     => 0,
			'attempts'   => 0,
			'log_buffer' => [],
			'result'     => [],
		];
	}

	/**
	 * Is there anything running or waiting?
	 */
	public static function has_work(): bool {
		return null !== Job_Store::current() || [] !== Job_Store::queue();
	}

	/**
	 * Run one step of the current job (starting the next queued one if
	 * needed). Returns false when another process holds the lock.
	 */
	public static function tick( ?int $budget = null ): bool {
		if ( ! Job_Store::lock() ) {
			return false;
		}

		Util::raise_limits();
		$budget   = $budget ?? Settings::time_budget();
		$deadline = microtime( true ) + $budget;

		try {
			$job = Job_Store::current();

			if ( null === $job ) {
				$queue = Job_Store::queue();
				if ( [] === $queue ) {
					return true;
				}
				$job = array_shift( $queue );
				Job_Store::save_queue( $queue );
				$job = self::begin( $job );
				Job_Store::save_current( $job );
			}

			if ( self::STATUS_RUNNING === $job['status'] ) {
				$job = self::run_step( $job, $deadline );
				Job_Store::save_current( $job );
			}

			if ( self::is_terminal( (string) $job['status'] ) ) {
				self::end( $job );
				Job_Store::save_current( null );
			}
		} finally {
			Job_Store::unlock();
		}

		if ( self::has_work() ) {
			self::kick();
		}

		return true;
	}

	/**
	 * Run a job to completion in this request. Used by WP-CLI and by the
	 * backup that runs right before an update, where the caller has to wait
	 * for the result anyway. Does not touch the queue.
	 *
	 * @param array<string, mixed> $params   Handler parameters.
	 * @param callable|null        $progress Called with the job after each step.
	 * @return array<string, mixed> The finished job.
	 */
	public static function run_now( string $type, array $params, string $trigger, ?callable $progress = null ): array {
		Paths::ensure();
		Util::raise_limits();

		$job = self::begin( self::new_job( $type, $params, $trigger ) );

		while ( self::STATUS_RUNNING === $job['status'] ) {
			$job = self::run_step( $job, microtime( true ) + Settings::time_budget() );
			if ( null !== $progress ) {
				$progress( $job );
			}
		}

		self::end( $job );

		return $job;
	}

	/**
	 * @param array<string, mixed> $job Queued job.
	 * @return array<string, mixed>
	 */
	private static function begin( array $job ): array {
		$job['status']  = self::STATUS_RUNNING;
		$job['started'] = time();
		$job['run_id']  = Run_Repository::start( $job );

		$handler = self::handler( (string) $job['type'] );
		if ( null === $handler ) {
			$job['status'] = self::STATUS_FAILED;
			$job['error']  = 'Unknown job type.';
			return $job;
		}

		try {
			$job = $handler->start( $job );
		} catch ( \Throwable $e ) {
			$job['status'] = self::STATUS_FAILED;
			$job['error']  = Util::message( $e );
		}

		self::flush_log( $job );

		return $job;
	}

	/**
	 * @param array<string, mixed> $job Running job.
	 * @return array<string, mixed>
	 */
	private static function run_step( array $job, float $deadline ): array {
		$handler = self::handler( (string) $job['type'] );
		if ( null === $handler ) {
			$job['status'] = self::STATUS_FAILED;
			return $job;
		}

		if ( is_file( Paths::work_dir() . '/cancel.flag' ) ) {
			$job['status'] = self::STATUS_CANCELLED;
			return self::log( $job, 'Cancelled by an administrator.' );
		}

		try {
			$job             = $handler->step( $job, $deadline );
			$job['attempts'] = 0;
		} catch ( \Throwable $e ) {
			++$job['attempts'];
			$job = self::log( $job, sprintf( 'Error: %s', Util::message( $e ) ) );

			if ( (int) $job['attempts'] >= self::MAX_ATTEMPTS ) {
				$job['status'] = self::STATUS_FAILED;
				$job['error']  = Util::message( $e );
			}
		}

		self::flush_log( $job );

		return $job;
	}

	/**
	 * @param array<string, mixed> $job Finished job.
	 */
	private static function end( array $job ): void {
		$handler = self::handler( (string) $job['type'] );

		if ( null !== $handler ) {
			try {
				$handler->cleanup( $job );
			} catch ( \Throwable $e ) {
				$job = self::log( $job, 'Cleanup: ' . Util::message( $e ) );
			}
		}

		$status = Run_Repository::STATUS_SUCCESS;
		if ( self::STATUS_FAILED === $job['status'] ) {
			$status = Run_Repository::STATUS_FAILED;
		} elseif ( self::STATUS_CANCELLED === $job['status'] ) {
			$status = Run_Repository::STATUS_CANCELLED;
		} elseif ( ! empty( $job['warnings'] ) ) {
			$status = Run_Repository::STATUS_WARNING;
		}

		$job['run_status'] = $status;

		if ( '' !== (string) $job['error'] ) {
			$job = self::log( $job, 'Failed: ' . $job['error'] );
		}
		$job = self::log(
			$job,
			sprintf( 'Finished: %s after %d seconds.', $status, max( 0, time() - (int) $job['started'] ) )
		);
		self::flush_log( $job );

		$result = (array) $job['result'];

		Run_Repository::update(
			(int) $job['run_id'],
			[
				'status'       => $status,
				'finished_at'  => gmdate( 'Y-m-d H:i:s' ),
				'backup_id'    => (string) ( $result['backup_id'] ?? '' ),
				'backup_type'  => (string) ( $result['backup_type'] ?? '' ),
				'components'   => implode( ',', (array) ( $result['components'] ?? $job['params']['components'] ?? [] ) ),
				'table_count'  => (int) ( $result['table_count'] ?? 0 ),
				'file_count'   => (int) ( $result['file_count'] ?? 0 ),
				'size_bytes'   => (int) ( $result['size'] ?? 0 ),
				'destinations' => (string) wp_json_encode( (array) ( $result['destinations'] ?? [] ) ),
				'summary'      => '' !== (string) $job['error'] ? (string) $job['error'] : implode( "\n", array_slice( (array) $job['warnings'], 0, 20 ) ),
			]
		);

		wp_delete_file( Paths::work_dir() . '/cancel.flag' );
		Job_Store::save_last_finished( $job );
		Email_Report::maybe_send( (int) $job['run_id'] );

		/**
		 * Fires when a job has finished, whatever the outcome.
		 *
		 * @param array<string, mixed> $job    The job, including `result`.
		 * @param string               $status success|warning|failed|cancelled.
		 */
		do_action( 'ekbm_job_finished', $job, $status );
	}

	private static function is_terminal( string $status ): bool {
		return in_array( $status, [ self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_CANCELLED ], true );
	}

	/**
	 * Ask for the job to stop. Takes effect at the start of the next step.
	 */
	public static function cancel(): void {
		// A flag file rather than a field in the job state: the step running
		// right now holds the state in memory and would overwrite the field.
		if ( null !== Job_Store::current() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- flag file in the protected work directory.
			file_put_contents( Paths::work_dir() . '/cancel.flag', (string) time() );
		}
		Job_Store::save_queue( [] );
		self::kick();
	}

	/**
	 * Add a log line to a job. Buffered and written to the report row after
	 * each step.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>
	 */
	public static function log( array $job, string $message ): array {
		$job['log_buffer'][] = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message;
		return $job;
	}

	/**
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>
	 */
	public static function warn( array $job, string $message ): array {
		$job['warnings'][] = $message;
		return self::log( $job, 'Warning: ' . $message );
	}

	/**
	 * @param array<string, mixed> $job Job (by reference: the buffer is emptied).
	 */
	private static function flush_log( array &$job ): void {
		Run_Repository::append_log( (int) $job['run_id'], (array) $job['log_buffer'] );
		$job['log_buffer'] = [];
	}

	// -------------------------------------------------------------------------
	// Hand-over between requests
	// -------------------------------------------------------------------------

	/**
	 * Make sure somebody runs the next step soon.
	 */
	public static function kick(): void {
		if ( ! wp_next_scheduled( Installer::CRON_JOB_TICK ) ) {
			wp_schedule_single_event( time() + 60, Installer::CRON_JOB_TICK );
		}

		/**
		 * Filters whether the runner sends loopback requests. Disable on hosts
		 * where loopbacks hang; WP-Cron and the admin screen still drive jobs.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'ekbm_use_loopback', true ) ) {
			return;
		}

		$url = add_query_arg( 'action', 'ekbm_tick', admin_url( 'admin-ajax.php' ) );

		wp_remote_post(
			$url,
			[
				'timeout'   => 0.5,
				'blocking'  => false,
				// WordPress's own convention for requests to itself (see
				// spawn_cron()): verification is off by default because local
				// certificates are often self-signed, and can be switched on
				// with the core filter. The request carries an HMAC that only
				// lets the receiver advance the job already queued.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter for loopback TLS verification.
				'body'      => [ 'key' => hash_hmac( 'sha256', 'ekbm-tick', Job_Store::runner_key() ) ],
			]
		);
	}

	public function cron_tick(): void {
		self::tick();
	}

	/**
	 * The loopback endpoint. Unauthenticated by necessity (it is called by
	 * the server itself, without cookies), so it accepts nothing but a valid
	 * HMAC and can do nothing but advance the job that is already queued.
	 */
	public function loopback_tick(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authenticated by the HMAC below; there is no user session on a loopback.
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['key'] ) ) : '';

		if ( ! hash_equals( hash_hmac( 'sha256', 'ekbm-tick', Job_Store::runner_key() ), $key ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}

		ignore_user_abort( true );
		self::tick();
		wp_die( '', '', [ 'response' => 200 ] );
	}

	/**
	 * Progress summary for the admin screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$job  = Job_Store::current();
		$last = Job_Store::last_finished();

		return [
			'running' => null !== $job,
			'queued'  => count( Job_Store::queue() ),
			'job'     => null === $job ? null : [
				'id'       => $job['id'],
				'type'     => $job['type'],
				'phase'    => $job['phase'],
				'progress' => (int) $job['progress'],
				'message'  => (string) $job['message'],
				'started'  => (int) $job['started'],
				'updated'  => (int) $job['updated'],
			],
			'last'    => null === $last ? null : [
				'id'       => $last['id'],
				'type'     => $last['type'],
				'status'   => (string) ( $last['run_status'] ?? $last['status'] ),
				'message'  => (string) $last['message'],
				'error'    => (string) $last['error'],
				'run_id'   => (int) $last['run_id'],
				'finished' => (int) $last['updated'],
			],
		];
	}
}
