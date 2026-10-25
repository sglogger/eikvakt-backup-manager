<?php
/**
 * Streams a dump written by Database_Dumper into temporary tables.
 *
 * Nothing on the live site is touched while importing: every table is
 * created under a temporary name and only swapped in, all at once, after the
 * whole dump (and the search/replace) has gone through. A restore that fails
 * half-way leaves the site exactly as it was.
 *
 * The dump format puts every statement on its own line, which lets the
 * importer rename tables with anchored regular expressions instead of
 * parsing SQL.
 *
 * A dump may come from another site or an untrusted storage location, so it
 * is treated as untrusted input. Only the three statement shapes our own
 * dumper writes are accepted — `DROP TABLE IF EXISTS`, `CREATE TABLE … (…)`
 * and `INSERT INTO … VALUES (…)` — exactly as written, and only ever against
 * the temporary table they are mapped to. Header lines (comments, `SET`) are
 * skipped, never executed. Any other line aborts the restore before a single
 * live table has been touched.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sql_Importer {

	private const TABLE = '`((?:[^`]|``)+)`';

	/** Statement shapes our dumper writes, with what must follow the table name. */
	private const SHAPES = [
		'DROP TABLE IF EXISTS' => '/^DROP TABLE IF EXISTS ' . self::TABLE . ';$/',
		'CREATE TABLE'         => '/^CREATE TABLE ' . self::TABLE . ' \(.*\)[^()]*;$/s',
		'INSERT INTO'          => '/^INSERT INTO ' . self::TABLE . ' VALUES \(.*\);$/s',
	];

	/** Header lines of our dumper: skipped, never executed. */
	public static function is_header_line( string $line ): bool {
		return '' === $line
			|| str_starts_with( $line, '-- ' )
			|| str_starts_with( $line, '/*!40101 SET NAMES ' )
			|| 1 === preg_match( '/^SET (FOREIGN_KEY_CHECKS=0|SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\');$/', $line );
	}

	/**
	 * Map source table names to target and temporary names.
	 *
	 * @param string[] $tables        Source table names to restore.
	 * @param string   $source_prefix Table prefix of the source site.
	 * @param string   $tag           Short unique tag for the temporary names.
	 * @return array<string, array{target:string, temp:string}>
	 */
	public static function map_tables( array $tables, string $source_prefix, string $target_prefix, string $tag ): array {
		$map = [];
		foreach ( $tables as $table ) {
			$suffix = str_starts_with( $table, $source_prefix ) ? substr( $table, strlen( $source_prefix ) ) : $table;
			$target = $target_prefix . $suffix;
			$temp   = substr( 'afbt' . $tag . '_' . $target, 0, 64 );

			$map[ $table ] = [
				'target' => $target,
				'temp'   => $temp,
			];
		}
		return $map;
	}

	/**
	 * Rewrite one dump line onto its temporary table. Returns null for
	 * statements about tables that are not being restored; throws
	 * \UnexpectedValueException for anything that is not one of the three
	 * accepted statement shapes.
	 *
	 * @param array<string, array{target:string, temp:string}> $map Table map.
	 */
	public static function rewrite( string $line, array $map ): ?string {
		foreach ( self::SHAPES as $keyword => $shape ) {
			if ( 1 !== preg_match( $shape, $line, $m ) ) {
				continue;
			}

			// Table options that place data files elsewhere on the server.
			if ( 'CREATE TABLE' === $keyword && 1 === preg_match( '/\b(DATA|INDEX)\s+DIRECTORY\b/i', $line ) ) {
				break;
			}

			$table = str_replace( '``', '`', $m[1] );
			if ( ! isset( $map[ $table ] ) ) {
				return null;
			}

			// $m[1] is the name exactly as quoted in the line.
			$original = $keyword . ' `' . $m[1] . '`';
			$temp     = '`' . str_replace( '`', '``', $map[ $table ]['temp'] ) . '`';

			return $keyword . ' ' . $temp . substr( $line, strlen( $original ) );
		}

		throw new \UnexpectedValueException( 'statement-not-allowed' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function start( string $file ): array {
		return [
			'file'       => $file,
			'offset'     => 0,
			'statements' => 0,
			'done'       => false,
		];
	}

	/**
	 * @param array<string, mixed>                             $state State.
	 * @param array<string, array{target:string, temp:string}> $map   Table map.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, array $map, float $deadline ): array {
		global $wpdb;

		$gz = gzopen( (string) $state['file'], 'rb' );
		if ( false === $gz ) {
			throw new \RuntimeException( 'The database dump could not be opened.' );
		}

		if ( (int) $state['offset'] > 0 && 0 !== gzseek( $gz, (int) $state['offset'] ) ) {
			gzclose( $gz );
			throw new \RuntimeException( 'The database dump could not be resumed.' );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- executing a database dump; statements come from our own dump format and only touch temporary tables.
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
		$wpdb->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );

		$buffer = '';

		while ( microtime( true ) < $deadline ) {
			$chunk = gzgets( $gz, 16 * 1048576 );
			if ( false === $chunk ) {
				break;
			}

			$buffer .= $chunk;

			// Long statements arrive in pieces; wait for the line end.
			if ( ! str_ends_with( $buffer, "\n" ) && ! gzeof( $gz ) ) {
				continue;
			}

			$line   = rtrim( $buffer, "\r\n" );
			$buffer = '';

			$state['offset'] = gztell( $gz );

			if ( self::is_header_line( $line ) ) {
				continue;
			}

			try {
				$sql = self::rewrite( $line, $map );
			} catch ( \UnexpectedValueException $e ) {
				gzclose( $gz );
				$state['error'] = sprintf(
					'The database dump contains a statement that is not part of the backup format (near: %s). The restore was stopped before anything on the live site was changed.',
					substr( $line, 0, 60 )
				);
				return $state;
			}
			if ( null === $sql ) {
				continue;
			}

			// Bypass $wpdb's charset re-validation: it can rewrite or drop
			// non-UTF-8 data, which would corrupt a byte-exact restore.
			$wpdb->check_current_query = false;

			if ( false === $wpdb->query( rtrim( $sql, ';' ) ) ) {
				gzclose( $gz );
				throw new \RuntimeException( esc_html( 'SQL error: ' . $wpdb->last_error ) );
			}

			++$state['statements'];
		}
		// phpcs:enable

		if ( gzeof( $gz ) && '' === $buffer ) {
			$state['done'] = true;
		}

		gzclose( $gz );

		return $state;
	}
}
