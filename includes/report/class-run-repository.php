<?php
/**
 * Reports: one row per backup, restore or migration run, with its log.
 *
 * The table is one of the plugin's own and is excluded from every backup and
 * every restore, so the history of what happened survives a database
 * rollback — including the record of that rollback.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Run_Repository {

	public const STATUS_RUNNING   = 'running';
	public const STATUS_SUCCESS   = 'success';
	public const STATUS_WARNING   = 'warning';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * @param array<string, mixed> $job Job.
	 */
	public static function start( array $job ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table.
		$wpdb->insert(
			Installer::table_runs(),
			[
				'job_id'      => (string) $job['id'],
				'kind'        => (string) $job['type'],
				'run_trigger' => (string) $job['trigger'],
				'status'      => self::STATUS_RUNNING,
				'components'  => implode( ',', (array) ( $job['params']['components'] ?? [] ) ),
				'started_at'  => gmdate( 'Y-m-d H:i:s' ),
				'log'         => '',
			]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Append log lines.
	 *
	 * @param string[] $lines Lines without timestamps.
	 */
	public static function append_log( int $run_id, array $lines ): void {
		if ( $run_id <= 0 || [] === $lines ) {
			return;
		}

		global $wpdb;
		$table = Installer::table_runs();
		$text  = implode( "\n", $lines ) . "\n";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table; the name is built from $wpdb->prefix and every value is bound.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET log = CONCAT(COALESCE(log, ''), %s) WHERE id = %d", $text, $run_id ) );
	}

	/**
	 * @param array<string, mixed> $fields Columns to update.
	 */
	public static function update( int $run_id, array $fields ): void {
		if ( $run_id <= 0 ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table.
		$wpdb->update( Installer::table_runs(), $fields, [ 'id' => $run_id ] );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $run_id ): ?array {
		global $wpdb;
		$table = Installer::table_runs();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table; the name is built from $wpdb->prefix and every value is bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $run_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function query( int $per_page, int $page, string $status = '', string $kind = '' ): array {
		global $wpdb;
		$table = Installer::table_runs();

		$where  = 'WHERE 1=1';
		$params = [];

		if ( '' !== $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}
		if ( '' !== $kind ) {
			$where   .= ' AND kind = %s';
			$params[] = $kind;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table; the name is built from $wpdb->prefix, the WHERE clause only from fixed fragments, and every value is bound.
		$total = (int) $wpdb->get_var( [] === $params ? "SELECT COUNT(*) FROM {$table} {$where}" : $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $params ) );

		$params[] = $per_page;
		$params[] = max( 0, ( $page - 1 ) * $per_page );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, job_id, kind, run_trigger, status, backup_id, backup_type, components, table_count, file_count, size_bytes, destinations, started_at, finished_at, summary FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params ),
			ARRAY_A
		);
		// phpcs:enable

		return [
			'rows'  => is_array( $rows ) ? $rows : [],
			'total' => $total,
		];
	}

	/**
	 * Last finished run of a kind (any status).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last( string $kind = 'backup' ): ?array {
		global $wpdb;
		$table = Installer::table_runs();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table; the name is built from $wpdb->prefix and every value is bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE kind = %s AND status <> %s ORDER BY id DESC LIMIT 1", $kind, self::STATUS_RUNNING ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Delete report rows older than the given number of days.
	 */
	public static function prune( int $days ): void {
		if ( $days <= 0 ) {
			return;
		}

		global $wpdb;
		$table = Installer::table_runs();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table; the name is built from $wpdb->prefix and every value is bound.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE started_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	public static function status_label( string $status ): string {
		switch ( $status ) {
			case self::STATUS_RUNNING:
				return __( 'Running', 'eikvakt-backup-manager' );
			case self::STATUS_SUCCESS:
				return __( 'Success', 'eikvakt-backup-manager' );
			case self::STATUS_WARNING:
				return __( 'Completed with warnings', 'eikvakt-backup-manager' );
			case self::STATUS_FAILED:
				return __( 'Failed', 'eikvakt-backup-manager' );
			case self::STATUS_CANCELLED:
				return __( 'Cancelled', 'eikvakt-backup-manager' );
		}
		return $status;
	}

	public static function status_color( string $status ): string {
		switch ( $status ) {
			case self::STATUS_SUCCESS:
				return '#00a32a';
			case self::STATUS_WARNING:
				return '#dba617';
			case self::STATUS_FAILED:
				return '#d63638';
			case self::STATUS_RUNNING:
				return '#2271b1';
		}
		return '#646970';
	}

	public static function kind_label( string $kind ): string {
		switch ( $kind ) {
			case 'backup':
				return __( 'Backup', 'eikvakt-backup-manager' );
			case 'restore':
				return __( 'Restore', 'eikvakt-backup-manager' );
			case 'pull':
				return __( 'Migration import', 'eikvakt-backup-manager' );
		}
		return $kind;
	}

	public static function trigger_label( string $trigger ): string {
		switch ( $trigger ) {
			case 'manual':
				return __( 'Manual', 'eikvakt-backup-manager' );
			case 'schedule':
				return __( 'Scheduled', 'eikvakt-backup-manager' );
			case 'pre-update':
				return __( 'Before update', 'eikvakt-backup-manager' );
			case 'cli':
				return __( 'WP-CLI', 'eikvakt-backup-manager' );
			case 'migration':
				return __( 'Migration', 'eikvakt-backup-manager' );
			case 'safety':
				return __( 'Before restore', 'eikvakt-backup-manager' );
		}
		return $trigger;
	}
}
