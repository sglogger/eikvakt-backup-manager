<?php
/**
 * Activation, schema creation, migration and uninstall.
 *
 * All schema and option defaults live here so there is exactly one place to
 * look when reasoning about what a fresh install produces.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installer {

	// -------------------------------------------------------------------------
	// Option names
	// -------------------------------------------------------------------------
	public const OPTION_VERSION        = 'ekbm_version';
	public const OPTION_DATA_VERSION   = 'ekbm_data_version';
	public const OPTION_SETTINGS       = 'ekbm_settings';
	public const OPTION_SCHEDULE       = 'ekbm_schedule';
	public const OPTION_SCHEDULE_STATE = 'ekbm_schedule_state';
	public const OPTION_RETENTION      = 'ekbm_retention';
	public const OPTION_DATABASE       = 'ekbm_database';
	public const OPTION_ENCRYPTION     = 'ekbm_encryption';
	public const OPTION_SECURITY       = 'ekbm_security';
	public const OPTION_DESTINATIONS   = 'ekbm_destinations';
	public const OPTION_STORAGE_KEY    = 'ekbm_storage_key';
	public const OPTION_EXPOSURE       = 'ekbm_exposure';
	public const OPTION_MIGRATION_KEYS = 'ekbm_migration_keys';
	public const OPTION_OAUTH_PENDING  = 'ekbm_oauth_pending';

	/**
	 * Every option this plugin owns. Used by uninstall() so cleanup can never
	 * drift out of sync with the list above.
	 *
	 * @return string[]
	 */
	private static function all_options(): array {
		return [
			self::OPTION_VERSION,
			self::OPTION_DATA_VERSION,
			self::OPTION_SETTINGS,
			self::OPTION_SCHEDULE,
			self::OPTION_SCHEDULE_STATE,
			self::OPTION_RETENTION,
			self::OPTION_DATABASE,
			self::OPTION_ENCRYPTION,
			self::OPTION_SECURITY,
			self::OPTION_DESTINATIONS,
			self::OPTION_STORAGE_KEY,
			self::OPTION_EXPOSURE,
			self::OPTION_MIGRATION_KEYS,
			self::OPTION_OAUTH_PENDING,
		];
	}

	// -------------------------------------------------------------------------
	// Cron hooks
	// -------------------------------------------------------------------------
	public const CRON_SCHEDULE_CHECK = 'ekbm_schedule_check';
	public const CRON_JOB_TICK       = 'ekbm_job_tick';
	public const CRON_EXPOSURE       = 'ekbm_exposure_check';

	/** Custom recurrence for the schedule check. */
	public const INTERVAL_FIVE_MINUTES = 'ekbm_five_minutes';

	/**
	 * Cron hook => recurrence. WP-Cron is request-driven, so on a quiet site
	 * these can run late — the dashboard says so when a backup is overdue.
	 *
	 * @return array<string, string>
	 */
	public static function cron_schedule(): array {
		return [
			self::CRON_SCHEDULE_CHECK => self::INTERVAL_FIVE_MINUTES,
			self::CRON_EXPOSURE       => 'daily',
		];
	}

	/**
	 * `cron_schedules` filter: the five-minute recurrence the schedule check
	 * runs on. Five minutes is the resolution of every schedule the plugin
	 * offers, so a finer interval would only cost requests.
	 *
	 * @param array<string, array<string, mixed>> $schedules Registered schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function cron_intervals( $schedules ): array {
		$schedules = (array) $schedules;

		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- the schedule check is a cheap comparison of two timestamps; five minutes is the resolution of the schedules offered.
		$schedules[ self::INTERVAL_FIVE_MINUTES ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Eikvakt Backup Manager)', 'eikvakt-backup-manager' ),
		];

		return $schedules;
	}

	// -------------------------------------------------------------------------
	// Table names
	// -------------------------------------------------------------------------

	public static function table_runs(): string {
		global $wpdb;
		return $wpdb->prefix . 'ekbm_runs';
	}

	/**
	 * Tables that belong to this plugin. They are never written into a backup
	 * and never touched by a restore: restoring last week's database must not
	 * also roll back the report of the restore that is running right now.
	 *
	 * @return string[]
	 */
	public static function own_tables(): array {
		return [ self::table_runs() ];
	}

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	public static function activate(): void {
		self::create_tables();
		self::seed_options();

		// The backup directory is created — and locked down — on activation,
		// so the very first backup never lands in an unprotected place.
		Paths::ensure();

		// plugins_loaded has already fired for this request, so the boot code
		// that registers the custom interval has not run yet.
		add_filter( 'cron_schedules', [ self::class, 'cron_intervals' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- five minutes, see cron_intervals().

		foreach ( self::cron_schedule() as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, $hook );
			}
		}

		update_option( self::OPTION_VERSION, EKBM_VERSION, false );
		update_option( self::OPTION_DATA_VERSION, EKBM_DATA_VERSION, false );
	}

	public static function deactivate(): void {
		foreach ( array_keys( self::cron_schedule() ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		wp_clear_scheduled_hook( self::CRON_JOB_TICK );
	}

	/**
	 * Bring schema and options up to date after an update that did not go
	 * through the activation hook (e.g. files replaced over SFTP).
	 */
	public static function maybe_migrate(): void {
		$stored = (string) get_option( self::OPTION_DATA_VERSION, '' );

		if ( EKBM_DATA_VERSION !== $stored ) {
			self::create_tables();
			self::seed_options();
			Paths::ensure();
			update_option( self::OPTION_DATA_VERSION, EKBM_DATA_VERSION, false );
		}

		if ( EKBM_VERSION !== (string) get_option( self::OPTION_VERSION, '' ) ) {
			update_option( self::OPTION_VERSION, EKBM_VERSION, false );
		}

		self::verify_tables();
	}

	/**
	 * Remove everything the plugin stored — but only when the administrator
	 * explicitly asked for it. Backup archives are a separate decision: losing
	 * the settings is an inconvenience, losing the backups can be a disaster.
	 */
	public static function uninstall(): void {
		$settings = (array) get_option( self::OPTION_SETTINGS, [] );

		foreach ( array_keys( self::cron_schedule() ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		wp_clear_scheduled_hook( self::CRON_JOB_TICK );

		if ( empty( $settings['delete_data_on_uninstall'] ) ) {
			return;
		}

		if ( ! empty( $settings['delete_backups_on_uninstall'] ) ) {
			require_once __DIR__ . '/class-paths.php';
			require_once __DIR__ . '/class-util.php';

			// Only a directory carrying our marker is ever deleted.
			$dir = Paths::backup_dir();
			if ( '' !== $dir && is_dir( $dir ) && Paths::is_ours( $dir ) ) {
				Util::delete_tree( $dir );
			}
		}

		foreach ( self::all_options() as $option ) {
			delete_option( $option );
		}

		delete_metadata( 'user', 0, 'ekbm_exposure_dismissed', '', true );

		global $wpdb;
		$runs = self::table_runs();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall drops our own table; the name is built from $wpdb->prefix.
		$wpdb->query( "DROP TABLE IF EXISTS {$runs}" );

		delete_transient( 'ekbm_tables_verified' );
	}

	/**
	 * Self-heal a table that went missing (e.g. a partial database restore by
	 * another tool). Checked at most once a day.
	 */
	private static function verify_tables(): void {
		if ( get_transient( 'ekbm_tables_verified' ) ) {
			return;
		}

		global $wpdb;
		$runs = self::table_runs();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema probe, must not be cached.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $runs ) ) );

		if ( $exists !== $runs ) {
			self::create_tables();
		}

		set_transient( 'ekbm_tables_verified', 1, DAY_IN_SECONDS );
	}

	/**
	 * dbDelta is picky: two spaces after PRIMARY KEY, `KEY` never `INDEX`,
	 * one field per line.
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$runs    = self::table_runs();

		dbDelta(
			"CREATE TABLE {$runs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_id varchar(64) NOT NULL DEFAULT '',
				kind varchar(20) NOT NULL DEFAULT '',
				run_trigger varchar(20) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT '',
				backup_id varchar(100) NOT NULL DEFAULT '',
				backup_type varchar(20) NOT NULL DEFAULT '',
				components varchar(255) NOT NULL DEFAULT '',
				table_count int(10) unsigned NOT NULL DEFAULT 0,
				file_count int(10) unsigned NOT NULL DEFAULT 0,
				size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				destinations text NULL,
				started_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				finished_at datetime NULL DEFAULT NULL,
				summary text NULL,
				log longtext NULL,
				PRIMARY KEY  (id),
				KEY job_id (job_id),
				KEY status (status),
				KEY started_at (started_at)
			) {$charset};"
		);
	}

	/**
	 * Write defaults for options that do not exist yet. Never overwrites a
	 * value the administrator has set.
	 */
	private static function seed_options(): void {
		foreach ( Settings::defaults() as $option => $defaults ) {
			if ( false === get_option( $option, false ) ) {
				add_option( $option, $defaults, '', false );
			}
		}

		if ( '' === (string) get_option( self::OPTION_STORAGE_KEY, '' ) ) {
			add_option( self::OPTION_STORAGE_KEY, Util::random_token( 12 ), '', false );
		}
	}
}
