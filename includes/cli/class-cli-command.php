<?php
/**
 * WP-CLI commands: `wp eikvakt <command>`.
 *
 * Jobs started from the command line run to completion in the foreground,
 * without the time budget or the loopback hand-over the web runner needs.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Back up, restore and migrate this site.
 */
final class Cli_Command {

	/**
	 * Create a backup.
	 *
	 * ## OPTIONS
	 *
	 * [--components=<list>]
	 * : Comma-separated: database,core,plugins,themes,uploads,content. Default: everything.
	 *
	 * [--type=<type>]
	 * : auto (incremental when possible), full, partial or database.
	 * ---
	 * default: full
	 * ---
	 *
	 * [--label=<text>]
	 * : Free text shown in the backup list.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eikvakt backup
	 *     wp eikvakt backup --components=core,plugins,themes,uploads,content --type=auto
	 *     wp eikvakt backup --components=database --type=database
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function backup( array $args, array $assoc_args ): void {
		$components = isset( $assoc_args['components'] )
			? Components::sanitize( explode( ',', (string) $assoc_args['components'] ) )
			: Components::ALL;

		$type = (string) ( $assoc_args['type'] ?? 'full' );
		if ( 'full' === $type && Components::ALL !== $components && [] !== Components::file_components( $components ) ) {
			$type = 'partial';
		}

		$job = $this->run(
			'backup',
			[
				'components' => $components,
				'type'       => $type,
				'label'      => (string) ( $assoc_args['label'] ?? '' ),
			]
		);

		\WP_CLI::success( sprintf( 'Backup %s created (%s).', $job['result']['backup_id'] ?? '?', Util::bytes( (int) ( $job['result']['size'] ?? 0 ) ) ) );
	}

	/**
	 * List known backups.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$rows = [];
		foreach ( Catalog::all() as $manifest ) {
			$rows[] = [
				'id'         => $manifest->id(),
				'type'       => $manifest->type(),
				'created'    => wp_date( 'Y-m-d H:i', $manifest->created() ),
				'components' => implode( ',', $manifest->components() ),
				'size'       => Util::bytes( $manifest->total_size() ),
				'encrypted'  => $manifest->is_encrypted() ? 'yes' : 'no',
				'keep'       => $manifest->is_kept() ? 'yes' : 'no',
				'locations'  => implode( ',', Catalog::locations( $manifest->id() ) ),
			];
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, [ 'id', 'type', 'created', 'components', 'size', 'encrypted', 'keep', 'locations' ] );
	}

	/**
	 * Restore a backup.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup id (see `wp eikvakt list`).
	 *
	 * [--components=<list>]
	 * : Comma-separated subset. Default: everything in the backup.
	 *
	 * [--tables=<list>]
	 * : Comma-separated subset of tables.
	 *
	 * [--replace=<pairs>]
	 * : Search/replace pairs, `old=>new`, separated by `|`.
	 *
	 * [--passphrase=<passphrase>]
	 * : Passphrase of an encrypted backup.
	 *
	 * [--delete-extra]
	 * : Remove files that are not in the backup.
	 *
	 * [--wp-config]
	 * : Also restore wp-config.php.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eikvakt restore example-com_20261019-020000_f_ab12cd34
	 *     wp eikvakt restore <id> --components=database --replace='https://old.example=>https://new.example'
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function restore( array $args, array $assoc_args ): void {
		$manifest = Catalog::get( (string) $args[0] );
		if ( null === $manifest ) {
			\WP_CLI::error( 'Unknown backup id.' );
		}

		$components = isset( $assoc_args['components'] ) ? Components::sanitize( explode( ',', (string) $assoc_args['components'] ) ) : $manifest->components();

		$pairs = [];
		foreach ( array_filter( explode( '|', (string) ( $assoc_args['replace'] ?? '' ) ) ) as $pair ) {
			$parts = explode( '=>', $pair, 2 );
			if ( 2 === count( $parts ) ) {
				$pairs[] = [ $parts[0], $parts[1] ];
			}
		}

		\WP_CLI::confirm( sprintf( 'Restore %s (%s) over this site?', $manifest->id(), implode( ', ', $components ) ), $assoc_args );

		$this->run(
			'restore',
			[
				'backup_id'    => $manifest->id(),
				'components'   => $components,
				'tables'       => isset( $assoc_args['tables'] ) ? explode( ',', (string) $assoc_args['tables'] ) : [],
				'replace'      => $pairs,
				'passphrase'   => Secret_Cipher::encrypt( (string) ( $assoc_args['passphrase'] ?? '' ) ),
				'delete_extra' => isset( $assoc_args['delete-extra'] ),
				'wp_config'    => isset( $assoc_args['wp-config'] ),
			]
		);

		\WP_CLI::success( 'Restore complete.' );
	}

	/**
	 * Import a backup from another site with a migration key.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Migration key created on the source site (Migration → Export).
	 *
	 * [--allow-private]
	 * : The source is on a private network (internal address, non-standard
	 * port or plain http). Without it, only public HTTPS sources are contacted.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function migrate( array $args, array $assoc_args = [] ): void {
		if ( null === Migration::parse_key( (string) $args[0] ) ) {
			\WP_CLI::error( 'That is not a valid migration key.' );
		}

		$job = $this->run(
			'pull',
			[
				'key'           => Secret_Cipher::encrypt( (string) $args[0] ),
				'allow_private' => isset( $assoc_args['allow-private'] ),
			]
		);

		\WP_CLI::success( sprintf( 'Imported %s. Restore it with: wp eikvakt restore %s', $job['result']['backup_id'] ?? '', $job['result']['backup_id'] ?? '' ) );
	}

	/**
	 * Mark a backup as "keep" so retention never deletes it, or remove the mark.
	 *
	 * Marking an incremental backup also protects the full backup and the
	 * incrementals it builds on.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup id (see `wp eikvakt list`).
	 *
	 * [--remove]
	 * : Remove the mark instead.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eikvakt keep example-com_20261019-020000_f_3fa9c2d81b07
	 *     wp eikvakt keep example-com_20261019-020000_f_3fa9c2d81b07 --remove
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function keep( array $args, array $assoc_args ): void {
		$keep = ! isset( $assoc_args['remove'] );
		if ( ! Catalog::set_keep( (string) $args[0], $keep ) ) {
			\WP_CLI::error( 'Unknown backup id.' );
		}
		\WP_CLI::success( $keep ? 'Marked as keep.' : 'Keep mark removed.' );
	}

	/**
	 * Apply the retention rules now.
	 */
	public function prune(): void {
		\WP_CLI::success( sprintf( '%d backup(s) removed.', Retention::apply() ) );
	}

	/**
	 * Rebuild the backup list from this server and every destination.
	 */
	public function rescan(): void {
		\WP_CLI::log( sprintf( 'This server: %d backup(s).', Catalog::rescan_local() ) );
		foreach ( Destinations::enabled() as $id => $destination ) {
			try {
				\WP_CLI::log( sprintf( '%s: %d backup(s).', $destination['name'], Destinations::rescan( (string) $id ) ) );
			} catch ( \Throwable $e ) {
				\WP_CLI::warning( sprintf( '%s: %s', $destination['name'], Util::message( $e ) ) );
			}
		}
	}

	/**
	 * Show schedules, the last backup and whether backups are reachable over HTTP.
	 */
	public function status(): void {
		$schedule = Settings::get( Installer::OPTION_SCHEDULE );
		$latest   = Catalog::latest();
		$exposure = Paths::check_exposure();

		\WP_CLI::log( 'Backup directory: ' . Paths::backup_dir() );
		\WP_CLI::log( 'Web exposure:     ' . $exposure['status'] );
		\WP_CLI::log( 'Files schedule:   ' . Scheduler::describe( (array) $schedule['files'] ) );
		\WP_CLI::log( 'DB schedule:      ' . Scheduler::describe( (array) $schedule['database'] ) );
		\WP_CLI::log( 'Latest backup:    ' . ( null !== $latest ? $latest->id() : 'none' ) );

		if ( 'exposed' === $exposure['status'] ) {
			\WP_CLI::warning( 'Backups are downloadable over HTTP! Move the backup directory or block it in the web server.' );
		}
	}

	/**
	 * @param array<string, mixed> $params Handler parameters.
	 * @return array<string, mixed>
	 */
	private function run( string $type, array $params ): array {
		$last = '';
		$job  = Job_Runner::run_now(
			$type,
			$params,
			'cli',
			static function ( array $job ) use ( &$last ): void {
				$line = sprintf( '[%3d%%] %s', (int) $job['progress'], (string) $job['message'] );
				if ( $line !== $last ) {
					\WP_CLI::log( $line );
					$last = $line;
				}
			}
		);

		if ( Job_Runner::STATUS_DONE !== $job['status'] ) {
			\WP_CLI::error( (string) ( $job['error'] ?: 'The job failed. See Backup Manager → Reports.' ) );
		}

		foreach ( (array) $job['warnings'] as $warning ) {
			\WP_CLI::warning( (string) $warning );
		}

		return $job;
	}
}
