<?php
/**
 * Pure-PHP, resumable SQL dump.
 *
 * mysqldump is unavailable on most shared hosts, so the dump is produced
 * through $wpdb, table by table and in row batches, appending to a gzip file
 * across as many requests as it takes.
 *
 * The output format is deliberately rigid: one statement per line. Values are
 * escaped by the database driver, which turns line breaks into `\n`, so no
 * statement ever spans lines. The importer relies on that to stream the dump
 * line by line and to rename tables on the way in.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Database_Dumper {

	public const HEADER = '-- ekbm-dump 1';

	private const ROWS_PER_QUERY = 1000;

	/** Upper bound of one INSERT statement, well below max_allowed_packet. */
	private const STATEMENT_BYTES = 1048576;

	/**
	 * Base tables (not views) belonging to this site, minus our own.
	 *
	 * @return string[]
	 */
	public static function site_tables(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema listing, must be current.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW FULL TABLES WHERE `Table_type` = %s', 'BASE TABLE' ), ARRAY_N );

		$tables = [];
		foreach ( $rows as $row ) {
			$name = (string) $row[0];
			if ( str_starts_with( $name, $wpdb->prefix ) && ! in_array( $name, Installer::own_tables(), true ) ) {
				$tables[] = $name;
			}
		}

		sort( $tables );
		return $tables;
	}

	/**
	 * Tables to dump according to the Database settings.
	 *
	 * @return string[]
	 */
	public static function selected_tables(): array {
		$settings = Settings::get( Installer::OPTION_DATABASE );
		$all      = self::site_tables();

		if ( 'selected' !== ( $settings['mode'] ?? 'all' ) ) {
			return $all;
		}

		$chosen = array_values( array_intersect( $all, array_map( 'strval', (array) ( $settings['tables'] ?? [] ) ) ) );

		return [] === $chosen ? $all : $chosen;
	}

	/**
	 * @param string[] $tables Tables to dump.
	 * @return array<string, mixed>
	 */
	public static function start( array $tables, string $file ): array {
		global $wpdb;

		$gz = gzopen( $file, 'wb6' );
		if ( false !== $gz ) {
			gzwrite(
				$gz,
				self::HEADER . ' prefix=' . $wpdb->prefix . "\n"
				. "/*!40101 SET NAMES utf8mb4 */;\n"
				. "SET FOREIGN_KEY_CHECKS=0;\n"
				. "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
			);
			gzclose( $gz );
		}

		return [
			'file'   => $file,
			'tables' => array_values( $tables ),
			'table'  => 0,
			'offset' => 0,
			'last'   => null,
			'rows'   => 0,
			'done'   => [] === $tables,
		];
	}

	/**
	 * @param array<string, mixed> $state State from start() or a previous step.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		global $wpdb;

		// Each request appends its own gzip member; concatenated members are a
		// valid gzip stream and every reader treats them as one file.
		$gz = gzopen( (string) $state['file'], 'ab6' );
		if ( false === $gz ) {
			$state['error'] = 'dump-not-writable';
			return $state;
		}

		while ( ! $state['done'] && microtime( true ) < $deadline ) {
			$tables = (array) $state['tables'];
			$index  = (int) $state['table'];

			if ( $index >= count( $tables ) ) {
				$state['done'] = true;
				break;
			}

			$table = (string) $tables[ $index ];

			if ( 0 === (int) $state['offset'] && null === $state['last'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names cannot be placeholders; the name comes from SHOW TABLES and is quoted.
				$create = $wpdb->get_row( 'SHOW CREATE TABLE ' . self::quote( $table ), ARRAY_N );
				if ( ! is_array( $create ) || empty( $create[1] ) ) {
					$state['skipped'][] = $table;
					++$state['table'];
					continue;
				}

				gzwrite( $gz, 'DROP TABLE IF EXISTS ' . self::quote( $table ) . ";\n" );
				gzwrite( $gz, str_replace( [ "\r\n", "\n", "\r" ], ' ', (string) $create[1] ) . ";\n" );
			}

			$key  = self::integer_primary_key( $table );
			$rows = self::fetch( $table, $key, $state );

			if ( empty( $rows ) ) {
				++$state['table'];
				$state['offset'] = 0;
				$state['last']   = null;
				continue;
			}

			$statement = '';
			$prefix    = 'INSERT INTO ' . self::quote( $table ) . ' VALUES ';

			foreach ( $rows as $row ) {
				$values = [];
				foreach ( $row as $value ) {
					$values[] = null === $value ? 'NULL' : "'" . self::escape( (string) $value ) . "'";
				}
				$tuple = '(' . implode( ',', $values ) . ')';

				if ( '' !== $statement && strlen( $statement ) + strlen( $tuple ) > self::STATEMENT_BYTES ) {
					gzwrite( $gz, $statement . ";\n" );
					$statement = '';
				}

				$statement .= ( '' === $statement ? $prefix : ',' ) . $tuple;
				++$state['rows'];
			}

			if ( '' !== $statement ) {
				gzwrite( $gz, $statement . ";\n" );
			}

			if ( null !== $key ) {
				$last          = end( $rows );
				$state['last'] = $last[ $key['position'] ];
			}
			$state['offset'] += count( $rows );

			if ( count( $rows ) < self::ROWS_PER_QUERY ) {
				++$state['table'];
				$state['offset'] = 0;
				$state['last']   = null;
			}
		}

		gzclose( $gz );

		return $state;
	}

	/**
	 * Keyset pagination when the table has a single integer primary key —
	 * OFFSET pagination re-reads everything before the offset and gets slower
	 * with every batch on a large table.
	 *
	 * @return array{column:string, position:int}|null
	 */
	private static function integer_primary_key( string $table ): ?array {
		static $cache = [];

		if ( array_key_exists( $table, $cache ) ) {
			return $cache[ $table ];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema probe; the table name comes from SHOW TABLES and is quoted.
		$columns = (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . self::quote( $table ), ARRAY_A );

		$primary = [];
		foreach ( $columns as $position => $column ) {
			if ( 'PRI' === ( $column['Key'] ?? '' ) ) {
				$primary[] = [
					'column'   => (string) $column['Field'],
					'position' => (int) $position,
					'type'     => strtolower( (string) $column['Type'] ),
				];
			}
		}

		$cache[ $table ] = null;
		if ( 1 === count( $primary ) && preg_match( '/^(tiny|small|medium|big)?int/', $primary[0]['type'] ) ) {
			$cache[ $table ] = [
				'column'   => $primary[0]['column'],
				'position' => $primary[0]['position'],
			];
		}

		return $cache[ $table ];
	}

	/**
	 * @param array{column:string, position:int}|null $key   Integer primary key.
	 * @param array<string, mixed>                    $state Dump state.
	 * @return array<int, array<int, string|null>>
	 */
	private static function fetch( string $table, ?array $key, array $state ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table and column names cannot be placeholders and come from SHOW TABLES / SHOW COLUMNS; every value is bound.
		if ( null !== $key ) {
			$column = self::quote( $key['column'] );
			if ( null === $state['last'] ) {
				$sql = $wpdb->prepare( 'SELECT * FROM ' . self::quote( $table ) . " ORDER BY {$column} ASC LIMIT %d", self::ROWS_PER_QUERY );
			} else {
				$sql = $wpdb->prepare( 'SELECT * FROM ' . self::quote( $table ) . " WHERE {$column} > %s ORDER BY {$column} ASC LIMIT %d", (string) $state['last'], self::ROWS_PER_QUERY );
			}
		} else {
			$sql = $wpdb->prepare( 'SELECT * FROM ' . self::quote( $table ) . ' LIMIT %d, %d', (int) $state['offset'], self::ROWS_PER_QUERY );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_N );
		// phpcs:enable

		return is_array( $rows ) ? $rows : [];
	}

	public static function quote( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * Escape through the database driver, then undo the placeholder escaping
	 * $wpdb adds to `%` — the dump is executed verbatim, not via prepare().
	 */
	private static function escape( string $value ): string {
		global $wpdb;
		return $wpdb->remove_placeholder_escape( $wpdb->_real_escape( $value ) );
	}
}
