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

	/**
	 * Storage engines a restored table may use. Others can reach beyond the
	 * table: MERGE writes into other tables, FEDERATED/CONNECT/SPIDER into
	 * other servers.
	 */
	private const ENGINES = 'InnoDB|MyISAM|Aria|MEMORY|ARCHIVE';

	/** Table options SHOW CREATE TABLE writes with a plain word or number as value. */
	private const TABLE_OPTIONS = 'AUTO_INCREMENT|ROW_FORMAT|PAGE_CHECKSUM|TRANSACTIONAL|CHECKSUM|DELAY_KEY_WRITE|KEY_BLOCK_SIZE|STATS_PERSISTENT|STATS_AUTO_RECALC|STATS_SAMPLE_PAGES|PACK_KEYS|AVG_ROW_LENGTH|MAX_ROWS|MIN_ROWS|PAGE_COMPRESSED|PAGE_COMPRESSION_LEVEL|ENCRYPTED|ENCRYPTION_KEY_ID';

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

			$table = str_replace( '``', '`', $m[1] );
			if ( ! isset( $map[ $table ] ) ) {
				return null;
			}

			// $m[1] is the name exactly as quoted in the line.
			$original = $keyword . ' `' . $m[1] . '`';
			$rest     = substr( $line, strlen( $original ) );

			// The shape alone is not enough: what follows the name must not
			// reach any table but the temporary one.
			if ( 'CREATE TABLE' === $keyword && ! self::is_table_definition( substr( $rest, 1, -1 ) ) ) {
				break;
			}
			if ( 'INSERT INTO' === $keyword && ! self::is_values_list( substr( $rest, strlen( ' VALUES ' ), -1 ) ) ) {
				break;
			}

			$temp = '`' . str_replace( '`', '``', $map[ $table ]['temp'] ) . '`';

			return $keyword . ' ' . $temp . $rest;
		}

		throw new \UnexpectedValueException( 'statement-not-allowed' );
	}

	/**
	 * Is this a column list with table options as SHOW CREATE TABLE writes
	 * them? `(…)` must hold column and index definitions only — no subquery,
	 * no comment (the server executes versioned comments) — and only listed
	 * options may follow it.
	 */
	public static function is_table_definition( string $definition ): bool {
		if ( ! str_starts_with( $definition, '(' ) ) {
			return false;
		}

		$length = strlen( $definition );
		$depth  = 0;
		$end    = -1;

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $definition[ $i ];

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$i = self::skip_quoted( $definition, $i );
				if ( $i < 0 ) {
					return false;
				}
				continue;
			}
			if ( '(' === $char ) {
				++$depth;
				continue;
			}
			if ( ')' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					$end = $i;
					break;
				}
				continue;
			}

			$next = $definition[ $i + 1 ] ?? '';
			if ( ( '/' === $char && '*' === $next ) || ( '-' === $char && '-' === $next ) || '#' === $char ) {
				return false;
			}
			if ( ctype_alpha( $char ) && ( 0 === $i || ! self::is_word_char( $definition[ $i - 1 ] ) ) ) {
				$word = strtoupper( substr( $definition, $i, strspn( $definition, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_', $i ) ) );
				if ( in_array( $word, [ 'SELECT', 'LOAD_FILE' ], true ) ) {
					return false;
				}
			}
		}

		if ( $end < 0 ) {
			return false;
		}

		$option = '(?:DEFAULT\s+)?(?:CHARSET|CHARACTER\s+SET|COLLATE)\s*=?\s*\w+'
			. '|ENGINE\s*=\s*(?:' . self::ENGINES . ')'
			. '|(?:' . self::TABLE_OPTIONS . ')\s*=\s*\w+'
			. "|COMMENT\s*=?\s*'(?:[^'\\\\]|\\\\.|'')*'";

		return 1 === preg_match( '/^(?:\s+(?:' . $option . '))*$/i', substr( $definition, $end + 1 ) );
	}

	/**
	 * Is this a list of row tuples made of literals only — NULL, numbers and
	 * quoted strings? No expression, function or subquery can run.
	 */
	public static function is_values_list( string $values ): bool {
		$length = strlen( $values );
		$i      = 0;

		while ( true ) {
			if ( '(' !== ( $values[ $i ] ?? '' ) ) {
				return false;
			}
			++$i;

			while ( true ) {
				$char = $values[ $i ] ?? '';

				if ( "'" === $char ) {
					$i = self::skip_quoted( $values, $i );
					if ( $i < 0 ) {
						return false;
					}
					++$i;
				} elseif ( 'NULL' === substr( $values, $i, 4 ) ) {
					$i += 4;
				} else {
					$n = strspn( $values, '-+0123456789.eE', $i );
					if ( 0 === $n || ! is_numeric( substr( $values, $i, $n ) ) ) {
						return false;
					}
					$i += $n;
				}

				$char = $values[ $i ] ?? '';
				++$i;
				if ( ')' === $char ) {
					break;
				}
				if ( ',' !== $char ) {
					return false;
				}
			}

			if ( $i === $length ) {
				return true;
			}
			if ( ',' !== $values[ $i ] ) {
				return false;
			}
			++$i;
		}
	}

	/**
	 * Position of the quote that closes the literal or identifier opening at
	 * $start, or -1. Strings know backslash escapes and doubled quotes,
	 * identifiers only doubled backticks — as the server reads them.
	 */
	private static function skip_quoted( string $sql, int $start ): int {
		$quote  = $sql[ $start ];
		$stops  = '`' === $quote ? '`' : $quote . '\\';
		$length = strlen( $sql );
		$i      = $start + 1;

		while ( $i < $length ) {
			$i += strcspn( $sql, $stops, $i );
			if ( $i >= $length ) {
				return -1;
			}
			if ( '\\' === $sql[ $i ] ) {
				$i += 2;
				continue;
			}
			if ( ( $sql[ $i + 1 ] ?? '' ) === $quote ) {
				$i += 2;
				continue;
			}
			return $i;
		}

		return -1;
	}

	private static function is_word_char( string $char ): bool {
		return ctype_alnum( $char ) || '_' === $char;
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
