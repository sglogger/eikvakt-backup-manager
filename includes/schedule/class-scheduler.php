<?php
/**
 * Starts scheduled backups.
 *
 * A single five-minute WP-Cron event checks both schedules (files and
 * database) and queues a job when one is due. The schedule state records the
 * last attempt as well as the last success: a failed run waits for its next
 * slot instead of being retried every five minutes, and the dashboard can
 * still say how old the newest good backup is.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scheduler {

	public const KINDS = [ 'files', 'database' ];

	public function register(): void {
		add_action( Installer::CRON_SCHEDULE_CHECK, [ self::class, 'check' ] );
		add_action( Installer::CRON_EXPOSURE, [ self::class, 'daily' ] );
	}

	/**
	 * @return array<string, int>
	 */
	public static function state(): array {
		$state = get_option( Installer::OPTION_SCHEDULE_STATE, [] );
		return is_array( $state ) ? array_map( 'intval', $state ) : [];
	}

	/**
	 * @param array<string, int> $state State.
	 */
	private static function save_state( array $state ): void {
		update_option( Installer::OPTION_SCHEDULE_STATE, $state, false );
	}

	/**
	 * Restart the clock of a schedule, e.g. after its settings changed.
	 */
	public static function anchor( string $kind ): void {
		$state                       = self::state();
		$state[ $kind . '_anchor' ]  = time();
		$state[ $kind . '_attempt' ] = 0;
		self::save_state( $state );
	}

	/**
	 * Record a successful backup of a kind.
	 */
	public static function record( string $kind, int $time ): void {
		$state                       = self::state();
		$state[ $kind . '_success' ] = max( $time, (int) ( $state[ $kind . '_success' ] ?? 0 ) );
		self::save_state( $state );
	}

	private static function base( string $kind ): int {
		$state = self::state();
		return max(
			(int) ( $state[ $kind . '_success' ] ?? 0 ),
			(int) ( $state[ $kind . '_attempt' ] ?? 0 ),
			(int) ( $state[ $kind . '_anchor' ] ?? 0 )
		);
	}

	/**
	 * Next due time of a kind, or null when its schedule is off.
	 */
	public static function next( string $kind ): ?int {
		$schedule = Settings::get( Installer::OPTION_SCHEDULE );
		$config   = (array) ( $schedule[ $kind ] ?? [] );
		$base     = self::base( $kind );

		if ( 0 === $base && 'off' !== ( $config['frequency'] ?? 'off' ) ) {
			self::anchor( $kind );
			$base = time();
		}

		return Schedule::next_run( $config, $base, time(), wp_timezone() );
	}

	public static function last_success( string $kind ): int {
		return (int) ( self::state()[ $kind . '_success' ] ?? 0 );
	}

	/**
	 * Cron callback: queue every schedule that is due.
	 */
	public static function check(): void {
		if ( ! Settings::is_configured() ) {
			return;
		}

		$schedule = Settings::get( Installer::OPTION_SCHEDULE );

		foreach ( self::KINDS as $kind ) {
			$config = (array) ( $schedule[ $kind ] ?? [] );
			$next   = self::next( $kind );

			if ( null === $next || $next > time() ) {
				continue;
			}

			$state                       = self::state();
			$state[ $kind . '_attempt' ] = time();
			self::save_state( $state );

			if ( 'files' === $kind ) {
				$components = Components::sanitize( $config['components'] ?? Components::FILE_COMPONENTS );
				if ( [] === Components::file_components( $components ) ) {
					continue;
				}
				Job_Runner::enqueue(
					'backup',
					[
						'components' => $components,
						'type'       => 'auto',
						'full_every' => max( 1, (int) ( $config['full_every'] ?? 7 ) ),
					],
					'schedule'
				);
			} else {
				Job_Runner::enqueue(
					'backup',
					[
						'components' => [ Components::DATABASE ],
						'type'       => 'database',
					],
					'schedule'
				);
			}
		}

		// Keep a job moving even if every loopback failed.
		if ( Job_Runner::has_work() && ! Job_Store::is_busy() ) {
			Job_Runner::tick();
		}
	}

	/**
	 * Daily housekeeping: re-test that backups are not reachable over HTTP
	 * and drop report rows older than a year.
	 */
	public static function daily(): void {
		Paths::check_exposure();
		Run_Repository::prune( 365 );
	}

	/**
	 * Translated description of a schedule, for the dashboard.
	 *
	 * @param array<string, mixed> $config Schedule config.
	 */
	public static function describe( array $config ): string {
		$time = (string) ( $config['time'] ?? '02:00' );

		switch ( (string) ( $config['frequency'] ?? 'off' ) ) {
			case 'interval':
				/* translators: %d: number of hours */
				return sprintf( _n( 'Every %d hour', 'Every %d hours', (int) $config['interval_hours'], 'eikvakt-backup-manager' ), (int) $config['interval_hours'] );
			case 'daily':
				/* translators: %s: time of day */
				return sprintf( __( 'Daily at %s', 'eikvakt-backup-manager' ), $time );
			case 'weekly':
				global $wp_locale;
				$weekday = (int) ( $config['weekday'] ?? 1 ) % 7;
				/* translators: 1: weekday name, 2: time of day */
				return sprintf( __( 'Weekly on %1$s at %2$s', 'eikvakt-backup-manager' ), $wp_locale->get_weekday( $weekday ), $time );
			case 'monthly':
				/* translators: 1: day of month, 2: time of day */
				return sprintf( __( 'Monthly on day %1$d at %2$s', 'eikvakt-backup-manager' ), (int) ( $config['monthday'] ?? 1 ), $time );
		}

		return __( 'Off', 'eikvakt-backup-manager' );
	}
}
