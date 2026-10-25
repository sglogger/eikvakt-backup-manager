<?php
/**
 * Option defaults and typed access to stored settings.
 *
 * One array option per area, as in the rest of the author's plugins: a save on
 * the Schedule tab can never clobber a value on the Retention tab, and every
 * option can be inspected on its own with `wp option get`.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	/**
	 * Option name => default value.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		return [
			Installer::OPTION_SETTINGS     => [
				'configured'                  => false,
				'backup_path'                 => '',
				'excludes'                    => '',
				'file_rules'                  => [],
				'exclude_larger_than_mb'      => 0,
				'part_size_mb'                => 250,
				'keep_local'                  => true,
				'email_mode'                  => 'failures',
				'email_recipients'            => '',
				'time_budget'                 => 20,
				'delete_data_on_uninstall'    => false,
				'delete_backups_on_uninstall' => false,
			],
			Installer::OPTION_SCHEDULE     => [
				'files'      => [
					'frequency'      => 'off',
					'interval_hours' => 12,
					'time'           => '02:00',
					'weekday'        => 1,
					'monthday'       => 1,
					'full_every'     => 7,
					'components'     => Components::FILE_COMPONENTS,
				],
				'database'   => [
					'frequency'      => 'off',
					'interval_hours' => 6,
					'time'           => '03:00',
					'weekday'        => 1,
					'monthday'       => 1,
				],
				'pre_update' => [
					'enabled'  => true,
					'database' => true,
				],
			],
			Installer::OPTION_RETENTION    => [
				'keep_chains'    => 4,
				'keep_database'  => 14,
				'keep_partial'   => 10,
				'max_age_days'   => 0,
				'gfs_enabled'    => false,
				'gfs_daily'      => 7,
				'gfs_weekly'     => 4,
				'gfs_monthly'    => 6,
				'local_chains'   => 0,
				'local_database' => 0,
				'local_days'     => 0,
				'remote_days'    => 0,
			],
			Installer::OPTION_DATABASE     => [
				'mode'   => 'all',
				'tables' => [],
			],
			Installer::OPTION_ENCRYPTION   => [
				'enabled'    => false,
				'passphrase' => '',
				'files'      => false,
				'hint'       => '',
			],
			Installer::OPTION_SECURITY     => [
				'password_enabled' => false,
				'password_hash'    => '',
				'session_minutes'  => 30,
			],
			Installer::OPTION_DESTINATIONS => [],
		];
	}

	/**
	 * Stored option merged over its defaults, one level deep for nested
	 * groups such as the two schedules.
	 *
	 * @return array<string, mixed>
	 */
	public static function get( string $option ): array {
		$defaults = self::defaults()[ $option ] ?? [];
		$stored   = get_option( $option, [] );
		$stored   = is_array( $stored ) ? $stored : [];

		if ( Installer::OPTION_DESTINATIONS === $option ) {
			return $stored;
		}

		$merged = array_merge( $defaults, $stored );

		foreach ( $defaults as $key => $value ) {
			if ( is_array( $value ) && ! array_is_list( $value ) && isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ) {
				$merged[ $key ] = array_merge( $value, $stored[ $key ] );
			}
		}

		return $merged;
	}

	/**
	 * @param array<string, mixed> $value New value.
	 */
	public static function update( string $option, array $value ): void {
		update_option( $option, $value, false );
	}

	public static function general(): array {
		return self::get( Installer::OPTION_SETTINGS );
	}

	public static function is_configured(): bool {
		return ! empty( self::general()['configured'] );
	}

	/**
	 * Seconds one request may spend on a job before handing over to the next.
	 * Conservative by default: shared hosts commonly kill PHP at 30 seconds.
	 */
	public static function time_budget(): int {
		$budget = (int) ( self::general()['time_budget'] ?? 20 );

		/**
		 * Filters the per-request working time of a backup/restore job.
		 *
		 * @param int $budget Seconds, clamped to 5–300.
		 */
		$budget = (int) apply_filters( 'ekbm_time_budget', $budget );

		return max( 5, min( 300, $budget ) );
	}

	/**
	 * Folder rules and patterns of the Files tab, ready for the scanner.
	 * The size limit is applied by the scanner itself, which reports skipped
	 * large files.
	 */
	public static function file_rules(): File_Rules {
		return new File_Rules( (array) ( self::general()['file_rules'] ?? [] ), self::exclude_patterns() );
	}

	/**
	 * User-defined exclusion patterns, one per line, plus built-in ones for
	 * directories that are caches or other plugins' backups.
	 *
	 * @return string[]
	 */
	public static function exclude_patterns(): array {
		$lines = preg_split( '/\R/', (string) ( self::general()['excludes'] ?? '' ) ) ?: [];
		$lines = array_values( array_filter( array_map( 'trim', $lines ), static fn( $l ) => '' !== $l && ! str_starts_with( $l, '#' ) ) );

		$builtin = [
			'content/cache/*',
			'content/upgrade/*',
			'content/upgrade-temp-backup/*',
			'content/updraft/*',
			'content/ai1wm-backups/*',
			'content/backups-dup-lite/*',
			'content/uploads/backwpup*',
			'content/wpvividbackups/*',
			'content/debug.log',
			'*/.git/*',
			'*/node_modules/*',
			'*.DS_Store',
		];

		/**
		 * Filters the exclusion patterns applied to every file backup.
		 *
		 * Patterns are fnmatch() globs against the archive path, which starts
		 * with `root/` (relative to ABSPATH) or `content/` (relative to
		 * WP_CONTENT_DIR).
		 *
		 * @param string[] $patterns Patterns.
		 */
		return (array) apply_filters( 'ekbm_exclude_patterns', array_merge( $builtin, $lines ) );
	}
}
