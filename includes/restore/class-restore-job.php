<?php
/**
 * The restore job: fetch → decrypt → plan → files → prune → database →
 * replace → swap.
 *
 * Parameters:
 *   backup_id      string    set to restore (an incremental restores the
 *                            state at its point in time, using its chain)
 *   components     string[]  subset of the set's components
 *   tables         string[]  optional subset of the set's tables
 *   source         string    destination to fetch from when not local
 *   passphrase     string    sealed with Secret_Cipher (encrypted sets)
 *   replace        array     [from, to] search/replace pairs
 *   delete_extra   bool      remove files that did not exist in the backup
 *   wp_config      bool      also restore wp-config.php (off by default:
 *                            it holds this server's database credentials)
 *
 * Files are written to a temporary name and renamed into place, so a reader
 * never sees a half-written file. Their modification times are restored too,
 * which keeps the next incremental backup from treating every restored file
 * as changed. The database is imported into temporary tables and swapped in
 * at the very end in one RENAME TABLE statement.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Restore_Job implements Job_Handler {

	private const PHASES = [
		'fetch'    => 0,
		'decrypt'  => 15,
		'plan'     => 25,
		'files'    => 30,
		'prune'    => 60,
		'database' => 62,
		'replace'  => 85,
		'swap'     => 95,
	];

	public function start( array $job ): array {
		$params = (array) $job['params'];
		$id     = (string) ( $params['backup_id'] ?? '' );

		$manifest = Catalog::get( $id );
		if ( null === $manifest ) {
			throw new \RuntimeException( 'Unknown backup.' );
		}
		if ( (int) $manifest->data['format'] > EKBM_FORMAT_VERSION ) {
			throw new \RuntimeException( 'This backup was made by a newer version of the plugin. Update the plugin first.' );
		}

		$components = array_values( array_intersect( $manifest->components(), Components::sanitize( $params['components'] ?? [] ) ) );
		if ( [] === $components ) {
			throw new \RuntimeException( 'Nothing selected to restore.' );
		}

		$file_components = Components::file_components( $components );
		$chain           = [] !== $file_components ? Catalog::chain_for( $id ) : [ $manifest ];
		if ( [] === $chain ) {
			throw new \RuntimeException( 'A backup this incremental backup depends on is missing. It cannot be restored.' );
		}

		$tables = $manifest->tables();
		if ( ! empty( $params['tables'] ) ) {
			$tables = array_values( array_intersect( $tables, array_map( 'strval', (array) $params['tables'] ) ) );
		}

		$dir = Job_Store::job_dir( (string) $job['id'] );

		$job['state'] = [
			'backup_id'  => $id,
			'components' => $components,
			'tables'     => in_array( Components::DATABASE, $components, true ) ? $tables : [],
			'chain'      => array_map( static fn( Manifest $m ) => $m->id(), $chain ),
			'dir'        => $dir,
			'phase'      => 'fetch',
			'fetch'      => null,
			'decrypt'    => null,
			'plan'       => null,
			'files'      => null,
			'prune'      => null,
			'import'     => null,
			'replace'    => null,
			'map'        => [],
		];
		$job['phase'] = 'fetch';

		return Job_Runner::log(
			$job,
			sprintf( 'Restore of %s started: %s%s.', $id, implode( ', ', $components ), count( $chain ) > 1 ? sprintf( ' (chain of %d backups)', count( $chain ) ) : '' )
		);
	}

	public function step( array $job, float $deadline ): array {
		$state    = (array) $job['state'];
		$manifest = Catalog::get( (string) $state['backup_id'] );
		if ( null === $manifest ) {
			throw new \RuntimeException( 'The backup disappeared from the catalog during the restore.' );
		}

		switch ( $state['phase'] ) {
			case 'fetch':
				[ $job, $state ] = $this->fetch( $job, $state, $manifest, $deadline );
				break;

			case 'decrypt':
				[ $job, $state ] = $this->decrypt( $job, $state, $deadline );
				break;

			case 'plan':
				[ $job, $state ] = $this->plan( $job, $state, $manifest );
				break;

			case 'files':
				[ $job, $state ] = $this->files( $job, $state, $deadline );
				break;

			case 'prune':
				[ $job, $state ] = $this->prune( $job, $state, $deadline );
				break;

			case 'database':
				[ $job, $state ] = $this->database( $job, $state, $manifest, $deadline );
				break;

			case 'replace':
				[ $job, $state ] = $this->replace( $job, $state, $deadline );
				break;

			case 'swap':
				[ $job, $state ] = $this->swap( $job, $state, $manifest );
				break;
		}

		$job['state']    = $state;
		$job['phase']    = $state['phase'];
		$job['progress'] = 'done' === $state['phase'] ? 100 : ( self::PHASES[ $state['phase'] ] ?? 0 );

		return $job;
	}

	// -------------------------------------------------------------------------
	// Phases
	// -------------------------------------------------------------------------

	/**
	 * Which files of which sets this restore needs. File parts of earlier
	 * chain members are needed only when the index points at them; that is
	 * decided in plan(), so fetch() first gets indexes and dumps, then the
	 * parts plan() asks for.
	 *
	 * @param array<string, mixed> $state State.
	 * @return string[]
	 */
	private static function needed_files( array $state, Manifest $manifest ): array {
		$names = [];

		if ( [] !== Components::file_components( (array) $state['components'] ) ) {
			$names = array_merge( $names, $manifest->files_of_kind( Manifest::KIND_INDEX ) );
		}
		if ( [] !== (array) $state['tables'] ) {
			$names = array_merge( $names, $manifest->files_of_kind( Manifest::KIND_DB ) );
		}
		foreach ( (array) ( $state['holders'] ?? [] ) as $holder ) {
			$set = Catalog::get( (string) $holder );
			if ( null !== $set ) {
				$names = array_merge( $names, $set->files_of_kind( Manifest::KIND_FILES ) );
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Local path of a set file: the backup directory if present there,
	 * otherwise the job's download directory.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private static function locate( array $state, string $name ): string {
		$local = Paths::backup_dir() . '/' . $name;
		if ( is_file( $local ) ) {
			return $local;
		}
		return $state['dir'] . '/' . $name;
	}

	/**
	 * Path of the usable (decrypted) copy of a set file.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private static function plain( array $state, string $name ): string {
		if ( str_ends_with( $name, Encryptor::EXTENSION ) ) {
			return $state['dir'] . '/plain-' . substr( $name, 0, -strlen( Encryptor::EXTENSION ) );
		}
		return self::locate( $state, $name );
	}

	/**
	 * @param array<string, mixed> $job   Job.
	 * @param array<string, mixed> $state State.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function fetch( array $job, array $state, Manifest $manifest, float $deadline ): array {
		$missing = [];
		foreach ( self::needed_files( $state, $manifest ) as $name ) {
			if ( ! is_file( self::locate( $state, $name ) ) ) {
				$missing[] = $name;
			}
		}

		if ( [] === $missing ) {
			$state['fetch'] = null;
			$state['phase'] = isset( $state['holders'] ) ? 'decrypt' : 'decrypt';
			return [ $job, $state ];
		}

		if ( null === $state['fetch'] ) {
			$source = self::source_for( $missing[0], $state, (string) ( $job['params']['source'] ?? '' ) );
			if ( '' === $source ) {
				throw new \RuntimeException( 'No storage destination holds the files this restore needs.' );
			}

			$set_files      = array_values( array_filter( $missing, static fn( $n ) => self::set_of( $n ) === self::set_of( $missing[0] ) ) );
			$state['fetch'] = Transfer::start( $set_files, [ $source ] );
			$job            = Job_Runner::log( $job, sprintf( 'Downloading %d file(s) from "%s".', count( $set_files ), Destinations::name( $source ) ) );
		}

		$state['fetch'] = Transfer::download_step( (array) $state['fetch'], (string) $state['dir'], $deadline );
		$job['message'] = __( 'Downloading backup files', 'eikvakt-backup-manager' );

		if ( Transfer::is_finished( (array) $state['fetch'] ) ) {
			foreach ( (array) $state['fetch']['targets'] as $target ) {
				if ( 'failed' === $target['status'] ) {
					throw new \RuntimeException( esc_html( 'Download failed: ' . $target['error'] ) );
				}
			}
			$state['fetch'] = null;
		}

		return [ $job, $state ];
	}

	/**
	 * Backup id a set file belongs to.
	 */
	private static function set_of( string $name ): string {
		return (string) strtok( $name, '.' );
	}

	/**
	 * @param array<string, mixed> $state State.
	 */
	private static function source_for( string $name, array $state, string $preferred ): string {
		$locations = Catalog::locations( self::set_of( $name ) );

		if ( '' !== $preferred && in_array( $preferred, $locations, true ) ) {
			return $preferred;
		}
		foreach ( $locations as $location ) {
			if ( Storage_Local::ID !== $location ) {
				return $location;
			}
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $job   Job.
	 * @param array<string, mixed> $state State.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function decrypt( array $job, array $state, float $deadline ): array {
		$manifest   = Catalog::get( (string) $state['backup_id'] );
		$passphrase = Secret_Cipher::decrypt( (string) ( $job['params']['passphrase'] ?? '' ) );

		foreach ( self::needed_files( $state, $manifest ) as $name ) {
			if ( ! str_ends_with( $name, Encryptor::EXTENSION ) ) {
				continue;
			}

			$target = self::plain( $state, $name );
			if ( is_file( $target ) && ( $state['decrypt']['name'] ?? '' ) !== $name ) {
				continue;
			}

			if ( '' === $passphrase ) {
				throw new \RuntimeException( 'This backup is encrypted. Enter its passphrase to restore it.' );
			}

			if ( ( $state['decrypt']['name'] ?? '' ) !== $name ) {
				$state['decrypt'] = [
					'name'  => $name,
					'state' => Encryptor::start_decrypt( self::locate( $state, $name ), $target . '.part' ),
				];
			}

			try {
				$state['decrypt']['state'] = Encryptor::decrypt_step( (array) $state['decrypt']['state'], $passphrase, $deadline );
			} catch ( \RuntimeException $e ) {
				$job['status'] = Job_Runner::STATUS_FAILED;
				$job['error']  = 'decrypt-auth' === Util::message( $e )
					? 'The passphrase is wrong, or the backup file has been altered.'
					: 'The encrypted file could not be read (' . Util::message( $e ) . ').';
				return [ $job, $state ];
			}

			$job['message'] = __( 'Decrypting', 'eikvakt-backup-manager' );

			if ( ! $state['decrypt']['state']['done'] ) {
				return [ $job, $state ];
			}

			rename( $target . '.part', $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- completing a decrypted file in the work directory.
			$state['decrypt'] = null;

			if ( microtime( true ) >= $deadline ) {
				return [ $job, $state ];
			}
		}

		$state['phase'] = isset( $state['holders'] ) ? 'files' : 'plan';
		return [ $job, $state ];
	}

	/**
	 * Read the index and write one extraction list per holder set.
	 *
	 * @param array<string, mixed> $job   Job.
	 * @param array<string, mixed> $state State.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function plan( array $job, array $state, Manifest $manifest ): array {
		$file_components = Components::file_components( (array) $state['components'] );

		if ( [] === $file_components ) {
			$state['holders'] = [];
			$state['phase']   = 'database';
			return [ $job, $state ];
		}

		$index_files = $manifest->files_of_kind( Manifest::KIND_INDEX );
		if ( [] === $index_files ) {
			throw new \RuntimeException( 'The backup has no file index.' );
		}

		$index      = File_Index::load( self::plain( $state, $index_files[0] ) );
		$include_wp = ! empty( $job['params']['wp_config'] );

		$lists = [];
		$count = 0;

		foreach ( $index as $path => [ $size, $mtime, $holder ] ) {
			if ( ! in_array( Components::classify( $path ), $file_components, true ) ) {
				continue;
			}
			if ( '' === self::target( $path, $include_wp ) ) {
				continue;
			}

			if ( ! isset( $lists[ $holder ] ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- extraction list in the protected work directory.
				$lists[ $holder ] = fopen( $state['dir'] . '/extract-' . $holder . '.txt', 'wb' );
			}
			fwrite( $lists[ $holder ], File_Index::format_line( $path, $size, $mtime ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- extraction list.
			++$count;
		}

		foreach ( $lists as $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- extraction list.
		}

		foreach ( array_keys( $lists ) as $holder ) {
			if ( ! in_array( $holder, (array) $state['chain'], true ) ) {
				throw new \RuntimeException( esc_html( sprintf( 'The index refers to backup %s, which is not part of this chain.', $holder ) ) );
			}
		}

		$state['holders'] = array_keys( $lists );
		$state['files']   = [
			'holder' => 0,
			'cursor' => 0,
			'done'   => 0,
			'total'  => $count,
			'failed' => [],
		];

		$job = Job_Runner::log( $job, sprintf( '%d files to restore from %d backup set(s).', $count, count( $lists ) ) );

		// The holders' archives may still have to be downloaded and decrypted.
		$state['phase'] = 'fetch';

		return [ $job, $state ];
	}

	/**
	 * Is a path inside one of the folders a limited backup covered? A backup
	 * of one plugin must never prune the files of every other plugin.
	 *
	 * @param string[] $only Folder or file archive paths; [] = everything.
	 */
	private static function within( string $path, array $only ): bool {
		if ( [] === $only ) {
			return true;
		}
		foreach ( $only as $prefix ) {
			$prefix = rtrim( $prefix, '/' );
			if ( $path === $prefix || str_starts_with( $path, $prefix . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Absolute path an archive path is restored to, or '' when it must not
	 * be written (or pruned).
	 *
	 * The protections are decided on the real place on disk, not on the
	 * spelling in the index: an index from elsewhere may address the same
	 * file in another way (`root/wp-content/…` instead of `content/…`, a
	 * symlinked parent folder).
	 */
	private static function target( string $archive_path, bool $include_wp_config ): string {
		// Only the canonical spelling our scanner writes: no `.`/`..` or empty
		// segments, and on Windows no backslash, a second separator there.
		if ( ! File_Rules::is_valid_path( $archive_path ) || ( '\\' === DIRECTORY_SEPARATOR && str_contains( $archive_path, '\\' ) ) ) {
			return '';
		}

		$target = File_Scanner::real_path( $archive_path );
		if ( '' === $target ) {
			return '';
		}

		$real    = self::canonical( $target );
		$content = self::canonical( File_Scanner::root_dir( 'content' ) );

		// wp-content is backed up under content/ only; under root/ it would
		// bypass the component choice and the protections below.
		if ( str_starts_with( $archive_path, 'root/' ) && self::is_within( $real, $content ) ) {
			return '';
		}

		// This plugin's own code: replacing it mid-restore with an older copy
		// would change the code that is running the restore.
		if ( self::is_within( $real, self::canonical( EKBM_DIR ) ) ) {
			return '';
		}

		if ( ! $include_wp_config && self::canonical( ABSPATH . 'wp-config.php' ) === $real ) {
			return '';
		}

		return $target;
	}

	private static function is_within( string $path, string $dir ): bool {
		return $path === $dir || str_starts_with( $path, $dir . '/' );
	}

	/**
	 * A path with symlinks resolved, also for a file that does not exist yet:
	 * the nearest existing parent is resolved, the rest appended.
	 */
	private static function canonical( string $path ): string {
		$path   = untrailingslashit( wp_normalize_path( $path ) );
		$suffix = '';

		while ( ! file_exists( $path ) && ! is_link( $path ) ) {
			$parent = dirname( $path );
			if ( $parent === $path ) {
				break;
			}
			$suffix = '/' . basename( $path ) . $suffix;
			$path   = $parent;
		}

		$real = realpath( $path );

		return untrailingslashit( wp_normalize_path( false !== $real ? $real : $path ) ) . $suffix;
	}

	/**
	 * @param array<string, mixed> $job   Job.
	 * @param array<string, mixed> $state State.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function files( array $job, array $state, float $deadline ): array {
		$progress = (array) $state['files'];
		$holders  = (array) $state['holders'];
		$count    = count( $holders );

		while ( (int) $progress['holder'] < $count && microtime( true ) < $deadline ) {
			$holder = (string) $holders[ (int) $progress['holder'] ];
			$set    = Catalog::get( $holder );
			if ( null === $set ) {
				throw new \RuntimeException( esc_html( 'Backup set missing: ' . $holder ) );
			}

			$zips = [];
			foreach ( $set->files_of_kind( Manifest::KIND_FILES ) as $part ) {
				$zip = new \ZipArchive();
				if ( true !== $zip->open( self::plain( $state, $part ), \ZipArchive::RDONLY ) ) {
					throw new \RuntimeException( esc_html( 'Could not open archive ' . $part ) );
				}
				$zips[] = $zip;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- extraction list in the protected work directory.
			$list = fopen( $state['dir'] . '/extract-' . $holder . '.txt', 'rb' );
			fseek( $list, (int) $progress['cursor'] );

			$finished = false;
			while ( microtime( true ) < $deadline ) {
				$line = fgets( $list );
				if ( false === $line ) {
					$finished = true;
					break;
				}

				$entry = File_Index::parse_line( $line );
				if ( null !== $entry && ! self::extract( $zips, $entry[0], $entry[2], ! empty( $job['params']['wp_config'] ) ) ) {
					$progress['failed'][] = $entry[0];
				}

				$progress['cursor'] = ftell( $list );
				++$progress['done'];
			}

			fclose( $list ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- extraction list.
			foreach ( $zips as $zip ) {
				$zip->close();
			}

			if ( $finished ) {
				++$progress['holder'];
				$progress['cursor'] = 0;
			}
		}

		$progress['failed'] = array_slice( (array) $progress['failed'], 0, 200 );
		$state['files']     = $progress;
		$job['message']     = sprintf(
			/* translators: 1: files restored, 2: total files */
			__( 'Restoring files: %1$s of %2$s', 'eikvakt-backup-manager' ),
			number_format_i18n( (int) $progress['done'] ),
			number_format_i18n( (int) $progress['total'] )
		);

		if ( (int) $progress['holder'] >= count( $holders ) ) {
			if ( ! empty( $progress['failed'] ) ) {
				$job = Job_Runner::warn( $job, sprintf( '%d files could not be written (permissions?). First: %s', count( $progress['failed'] ), $progress['failed'][0] ) );
			}
			$job            = Job_Runner::log( $job, sprintf( '%d files restored.', (int) $progress['done'] - count( $progress['failed'] ) ) );
			$state['phase'] = ! empty( $job['params']['delete_extra'] ) ? 'prune' : 'database';
		}

		return [ $job, $state ];
	}

	/**
	 * Write one archive entry to its place on this site.
	 *
	 * @param \ZipArchive[] $zips Parts of the holder set.
	 */
	private static function extract( array $zips, string $archive_path, int $mtime, bool $include_wp_config ): bool {
		// Checked again right before writing: folders may have changed since
		// the plan was made.
		$target = self::target( $archive_path, $include_wp_config );
		if ( '' === $target ) {
			return false;
		}

		foreach ( $zips as $zip ) {
			if ( false === $zip->locateName( $archive_path ) ) {
				continue;
			}

			$stream = $zip->getStream( $archive_path );
			if ( false === $stream ) {
				return false;
			}

			if ( ! is_dir( dirname( $target ) ) && ! wp_mkdir_p( dirname( $target ) ) ) {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- zip entry stream.
				return false;
			}

			$tmp = $target . '.ekbm-' . Util::random_token( 4 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- a non-writable target is reported, not fatal.
			$out = @fopen( $tmp, 'wb' );
			if ( false === $out ) {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- zip entry stream.
				return false;
			}

			Util::pipe( $stream, $out );
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- zip entry stream.
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- temporary output.

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic replace; failure is reported.
			if ( ! @rename( $tmp, $target ) ) {
				wp_delete_file( $tmp );
				return false;
			}

			// The original modification time, so the next incremental backup
			// does not see every restored file as changed.
			touch( $target, $mtime ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- WP_Filesystem::touch() is unavailable in cron/loopback context.
			return true;
		}

		return false;
	}

	/**
	 * Remove files in the restored components that the backup did not have.
	 *
	 * @param array<string, mixed> $job   Job.
	 * @param array<string, mixed> $state State.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function prune( array $job, array $state, float $deadline ): array {
		$file_components = Components::file_components( (array) $state['components'] );

		if ( null === $state['prune'] ) {
			$limited        = Catalog::get( (string) $state['backup_id'] );
			$state['prune'] = File_Scanner::start( $file_components, $state['dir'] . '/current.txt', null !== $limited ? (array) ( $limited->data['only'] ?? [] ) : [] );
		}

		if ( empty( $state['prune']['done'] ) ) {
			$state['prune'] = File_Scanner::step( (array) $state['prune'], $deadline );
			$job['message'] = __( 'Looking for files that were not in the backup', 'eikvakt-backup-manager' );
			return [ $job, $state ];
		}

		$manifest   = Catalog::get( (string) $state['backup_id'] );
		$index      = File_Index::load( self::plain( $state, $manifest->files_of_kind( Manifest::KIND_INDEX )[0] ) );
		$include_wp = ! empty( $job['params']['wp_config'] );
		$only       = array_map( 'strval', (array) ( $manifest->data['only'] ?? [] ) );
		$removed    = 0;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- scan list in the protected work directory.
		$list = fopen( (string) $state['prune']['list'], 'rb' );
		while ( false !== $list && false !== ( $line = fgets( $list ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- line reader.
			$entry = File_Index::parse_line( $line );
			if ( null === $entry || isset( $index[ $entry[0] ] ) || '' === self::target( $entry[0], $include_wp ) ) {
				continue;
			}
			if ( ! self::within( $entry[0], $only ) ) {
				continue;
			}
			wp_delete_file( File_Scanner::real_path( $entry[0] ) );
			++$removed;
		}
		if ( false !== $list ) {
			fclose( $list ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- scan list.
		}

		$job            = Job_Runner::log( $job, sprintf( 'Removed %d files that were not part of the backup.', $removed ) );
		$state['phase'] = 'database';

		return [ $job, $state ];
	}

	/**
	 * @param array<string, mixed> $job   Job.
	 * @param array<string, mixed> $state State.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function database( array $job, array $state, Manifest $manifest, float $deadline ): array {
		if ( [] === (array) $state['tables'] ) {
			$state['phase'] = 'done';
			$job            = $this->finish( $job, $state, $manifest );
			return [ $job, $state ];
		}

		global $wpdb;

		if ( null === $state['import'] ) {
			$db = $manifest->files_of_kind( Manifest::KIND_DB );
			if ( [] === $db ) {
				throw new \RuntimeException( 'The backup has no database dump.' );
			}

			$state['map']    = Sql_Importer::map_tables( (array) $state['tables'], $manifest->site( 'table_prefix' ), $wpdb->prefix, substr( (string) $job['id'], 0, 6 ) );
			$state['import'] = Sql_Importer::start( self::plain( $state, $db[0] ) );

			$job = Job_Runner::log( $job, sprintf( 'Importing %d tables into temporary tables.', count( $state['map'] ) ) );
		}

		$state['import'] = Sql_Importer::step( (array) $state['import'], (array) $state['map'], $deadline );

		// A rejected statement ends the restore at once; cleanup() drops the
		// temporary tables, the live database was never touched.
		if ( ! empty( $state['import']['error'] ) ) {
			$job['status'] = Job_Runner::STATUS_FAILED;
			$job['error']  = (string) $state['import']['error'];
			return [ $job, $state ];
		}
		$job['message'] = sprintf(
			/* translators: %s: number of SQL statements */
			__( 'Importing database: %s statements', 'eikvakt-backup-manager' ),
			number_format_i18n( (int) $state['import']['statements'] )
		);

		if ( $state['import']['done'] ) {
			$job            = Job_Runner::log( $job, sprintf( 'Database imported (%d statements).', $state['import']['statements'] ) );
			$state['phase'] = 'replace';
		}

		return [ $job, $state ];
	}

	/**
	 * Search/replace in the temporary tables, and fix prefixed option and
	 * user-meta names when the table prefix changes.
	 *
	 * @param array<string, mixed> $job   Job.
	 * @param array<string, mixed> $state State.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function replace( array $job, array $state, float $deadline ): array {
		global $wpdb;

		$manifest = Catalog::get( (string) $state['backup_id'] );
		$pairs    = Search_Replace::expand_pairs( (array) ( $job['params']['replace'] ?? [] ) );
		$map      = (array) $state['map'];

		if ( null === $state['replace'] ) {
			$state['replace'] = [
				'tables' => array_values( array_column( $map, 'temp' ) ),
				'table'  => 0,
				'last'   => null,
				'offset' => 0,
				'rows'   => 0,
			];
			self::fix_prefix( $map, $manifest->site( 'table_prefix' ), $wpdb->prefix );

			if ( [] !== $pairs ) {
				$job = Job_Runner::log( $job, 'Search and replace: ' . implode( '; ', array_map( static fn( $p ) => $p[0] . ' → ' . $p[1], array_slice( $pairs, 0, 10 ) ) ) );
			}
		}

		if ( [] === $pairs ) {
			$state['phase'] = 'swap';
			return [ $job, $state ];
		}

		$progress = (array) $state['replace'];
		$count    = count( $progress['tables'] );

		while ( (int) $progress['table'] < $count && microtime( true ) < $deadline ) {
			$table    = (string) $progress['tables'][ (int) $progress['table'] ];
			$progress = self::replace_batch( $table, $pairs, $progress );
		}

		$state['replace'] = $progress;
		$job['message']   = __( 'Replacing URLs and paths', 'eikvakt-backup-manager' );

		if ( (int) $progress['table'] >= count( $progress['tables'] ) ) {
			$job            = Job_Runner::log( $job, sprintf( 'Search and replace changed %d rows.', $progress['rows'] ) );
			$state['phase'] = 'swap';
		}

		return [ $job, $state ];
	}

	/**
	 * Process one batch of rows of one table.
	 *
	 * @param array<int, array{0:string, 1:string}> $pairs    Pairs.
	 * @param array<string, mixed>                  $progress Progress.
	 * @return array<string, mixed>
	 */
	private static function replace_batch( string $table, array $pairs, array $progress ): array {
		global $wpdb;

		$q = static fn( string $name ): string => Database_Dumper::quote( $name );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers come from SHOW COLUMNS on our own temporary table and are quoted; every value is bound.
		$columns = (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . $q( $table ), ARRAY_A );

		$primary = [];
		$text    = [];
		foreach ( $columns as $column ) {
			if ( 'PRI' === ( $column['Key'] ?? '' ) ) {
				$primary[] = (string) $column['Field'];
			}
			if ( preg_match( '/char|text|blob|json/i', (string) $column['Type'] ) ) {
				$text[] = (string) $column['Field'];
			}
		}

		$next_table = static function ( array $progress ): array {
			++$progress['table'];
			$progress['last']   = null;
			$progress['offset'] = 0;
			return $progress;
		};

		if ( [] === $primary || [] === $text ) {
			return $next_table( $progress );
		}

		$select = implode( ',', array_map( $q, array_unique( array_merge( $primary, $text ) ) ) );
		$limit  = 500;

		if ( 1 === count( $primary ) ) {
			$key   = $q( $primary[0] );
			$where = null === $progress['last'] ? '1=1' : $wpdb->prepare( "{$key} > %s", (string) $progress['last'] );
			$rows  = (array) $wpdb->get_results( "SELECT {$select} FROM " . $q( $table ) . " WHERE {$where} ORDER BY {$key} ASC LIMIT {$limit}", ARRAY_A );
		} else {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$select} FROM " . $q( $table ) . " LIMIT %d, {$limit}", (int) $progress['offset'] ), ARRAY_A );
		}

		foreach ( $rows as $row ) {
			$changes = [];
			foreach ( $text as $column ) {
				if ( null === $row[ $column ] ) {
					continue;
				}
				$new = Search_Replace::replace( (string) $row[ $column ], $pairs );
				if ( $new !== $row[ $column ] ) {
					$changes[ $column ] = $new;
				}
			}

			if ( [] !== $changes ) {
				$where = [];
				foreach ( $primary as $key ) {
					$where[ $key ] = $row[ $key ];
				}
				$wpdb->check_current_query = false;
				$wpdb->update( $table, $changes, $where );
				++$progress['rows'];
			}
		}
		// phpcs:enable

		if ( count( $rows ) < $limit ) {
			return $next_table( $progress );
		}

		if ( 1 === count( $primary ) ) {
			$last             = end( $rows );
			$progress['last'] = $last[ $primary[0] ];
		}
		$progress['offset'] += count( $rows );

		return $progress;
	}

	/**
	 * When the table prefix differs, WordPress also expects the prefix inside
	 * two places of data: the `<prefix>user_roles` option and the
	 * `<prefix>capabilities`/`<prefix>user_level`/... user meta keys.
	 *
	 * @param array<string, array{target:string, temp:string}> $map Table map.
	 */
	private static function fix_prefix( array $map, string $old, string $new ): void {
		if ( '' === $old || $old === $new ) {
			return;
		}

		global $wpdb;

		$options  = $map[ $old . 'options' ]['temp'] ?? '';
		$usermeta = $map[ $old . 'usermeta' ]['temp'] ?? '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own temporary tables; names are quoted, values bound.
		if ( '' !== $options ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Database_Dumper::quote( $options ) . ' SET option_name = CONCAT(%s, SUBSTRING(option_name, %d)) WHERE option_name LIKE %s', $new, strlen( $old ) + 1, $wpdb->esc_like( $old ) . '%' ) );
		}
		if ( '' !== $usermeta ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Database_Dumper::quote( $usermeta ) . ' SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s', $new, strlen( $old ) + 1, $wpdb->esc_like( $old ) . '%' ) );
		}
		// phpcs:enable
	}

	/**
	 * Swap all temporary tables in with one RENAME TABLE statement, which
	 * MySQL performs atomically.
	 *
	 * @param array<string, mixed> $job   Job.
	 * @param array<string, mixed> $state State.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function swap( array $job, array $state, Manifest $manifest ): array {
		global $wpdb;

		$renames = [];
		$drops   = [];
		$tag     = substr( (string) $job['id'], 0, 6 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- atomic table swap; identifiers come from our own table map and are quoted.
		foreach ( (array) $state['map'] as $entry ) {
			$target = (string) $entry['target'];
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $target ) ) );

			if ( $exists === $target ) {
				$old       = substr( 'afbo' . $tag . '_' . $target, 0, 64 );
				$renames[] = Database_Dumper::quote( $target ) . ' TO ' . Database_Dumper::quote( $old );
				$drops[]   = $old;
			}
			$renames[] = Database_Dumper::quote( (string) $entry['temp'] ) . ' TO ' . Database_Dumper::quote( $target );
		}

		self::preserve_own_options( (array) $state['map'] );

		if ( [] !== $renames && false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) {
			throw new \RuntimeException( esc_html( 'The restored tables could not be switched in: ' . $wpdb->last_error ) );
		}

		$state['swapped'] = true;

		foreach ( $drops as $old ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . Database_Dumper::quote( $old ) );
		}
		// phpcs:enable

		wp_cache_flush();

		$job            = Job_Runner::log( $job, sprintf( '%d tables switched in.', count( (array) $state['map'] ) ) );
		$state['phase'] = 'done';

		return [ $this->finish( $job, $state, $manifest ), $state ];
	}

	/**
	 * @param array<string, mixed> $job   Job.
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private function finish( array $job, array $state, Manifest $manifest ): array {
		$job['status']  = Job_Runner::STATUS_DONE;
		$job['message'] = __( 'Restore complete.', 'eikvakt-backup-manager' );
		$job['result']  = [
			'backup_id'   => $manifest->id(),
			'backup_type' => $manifest->type(),
			'components'  => (array) $state['components'],
			'table_count' => count( (array) $state['tables'] ),
			'file_count'  => (int) ( $state['files']['done'] ?? 0 ),
			'size'        => $manifest->total_size(),
		];

		// Restored rewrite rules and caches belong to the restored content.
		if ( in_array( Components::DATABASE, (array) $state['components'], true ) ) {
			delete_option( 'rewrite_rules' );
		}

		/**
		 * Fires after a restore has completed.
		 *
		 * @param Manifest             $manifest The restored set.
		 * @param array<string, mixed> $job      The job.
		 */
		do_action( 'ekbm_restored', $manifest, $job );

		return $job;
	}

	/**
	 * Carry this plugin's own options over into the restored options table.
	 *
	 * The backup holds the plugin settings of the day it was taken — or of
	 * another site, after a migration. Restoring those would point the
	 * plugin at a backup directory that does not exist here, at credentials
	 * sealed with another site's salts, and at a different storage key; the
	 * catalog of every backup taken since would be lost from view. Settings
	 * are therefore always the current site's, never the backup's.
	 *
	 * @param array<string, array{target:string, temp:string}> $map Table map.
	 */
	private static function preserve_own_options( array $map ): void {
		global $wpdb;

		$temp = '';
		foreach ( $map as $entry ) {
			if ( $wpdb->options === $entry['target'] ) {
				$temp = (string) $entry['temp'];
			}
		}
		if ( '' === $temp ) {
			return;
		}

		$like = $wpdb->esc_like( 'ekbm_' ) . '%';
		$live = Database_Dumper::quote( $wpdb->options );
		$tmp  = Database_Dumper::quote( $temp );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own temporary table and the live options table; identifiers quoted, values bound.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tmp} WHERE option_name LIKE %s", $like ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$tmp} (option_name, option_value, autoload) SELECT option_name, option_value, autoload FROM {$live} WHERE option_name LIKE %s", $like ) );
		// phpcs:enable
	}

	public function cleanup( array $job ): void {
		global $wpdb;

		// Temporary tables of an import that did not reach the swap.
		if ( empty( $job['state']['swapped'] ) ) {
			foreach ( (array) ( $job['state']['map'] ?? [] ) as $entry ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- dropping our own temporary table; the name is quoted.
				$wpdb->query( 'DROP TABLE IF EXISTS ' . Database_Dumper::quote( (string) $entry['temp'] ) );
			}
		}

		Util::delete_tree( Paths::work_dir() . '/job-' . preg_replace( '/[^a-z0-9]/', '', (string) $job['id'] ) );
	}
}
