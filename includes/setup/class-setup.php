<?php
/**
 * One-click setup: sensible defaults and a first backup.
 *
 *   - files: incremental every day at 02:00, a new full backup every 7th run
 *   - database: every day at 03:00
 *   - before every core, plugin and theme update: back up what changes,
 *     plus the database
 *   - keep 4 file chains, 14 database backups, 10 partial backups
 *   - store on this server (more destinations can be added at any time)
 *   - e-mail the site administrator when something goes wrong
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Setup {

	public static function run(): string {
		Paths::ensure();

		$schedule = Settings::get( Installer::OPTION_SCHEDULE );

		$schedule['files']      = array_merge(
			(array) $schedule['files'],
			[
				'frequency'  => 'daily',
				'time'       => '02:00',
				'full_every' => 7,
				'components' => Components::FILE_COMPONENTS,
			]
		);
		$schedule['database']   = array_merge(
			(array) $schedule['database'],
			[
				'frequency' => 'daily',
				'time'      => '03:00',
			]
		);
		$schedule['pre_update'] = [
			'enabled'  => true,
			'database' => true,
		];
		Settings::update( Installer::OPTION_SCHEDULE, $schedule );

		$retention = Settings::get( Installer::OPTION_RETENTION );
		$retention = array_merge(
			$retention,
			[
				'keep_chains'   => 4,
				'keep_database' => 14,
				'keep_partial'  => 10,
			]
		);
		Settings::update( Installer::OPTION_RETENTION, $retention );

		$general               = Settings::general();
		$general['configured'] = true;
		$general['email_mode'] = 'failures';
		Settings::update( Installer::OPTION_SETTINGS, $general );

		Scheduler::anchor( 'files' );
		Scheduler::anchor( 'database' );

		Paths::check_exposure();

		// The first backups run right now: the full file backup that starts
		// the incremental chain, and the database. Two sets rather than one,
		// so the scheduled incrementals can build on the file chain directly.
		$id = Job_Runner::enqueue(
			'backup',
			[
				'components' => Components::FILE_COMPONENTS,
				'type'       => 'auto',
				'full_every' => 7,
			],
			'manual'
		);

		Job_Runner::enqueue(
			'backup',
			[
				'components' => [ Components::DATABASE ],
				'type'       => 'database',
			],
			'manual'
		);

		return $id;
	}
}
