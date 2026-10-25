<?php
/**
 * Reports: every backup, restore and import that ran.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class Runs_List_Table extends \WP_List_Table {

	public function __construct() {
		parent::__construct(
			[
				'singular' => 'ekbm-run',
				'plural'   => 'ekbm-runs',
				'ajax'     => false,
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'started_at' => __( 'Started', 'eikvakt-backup-manager' ),
			'kind'       => __( 'Job', 'eikvakt-backup-manager' ),
			'status'     => __( 'Result', 'eikvakt-backup-manager' ),
			'components' => __( 'Included', 'eikvakt-backup-manager' ),
			'size'       => __( 'Size', 'eikvakt-backup-manager' ),
			'duration'   => __( 'Duration', 'eikvakt-backup-manager' ),
			'details'    => '',
		];
	}

	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters only.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$kind   = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( (string) $_GET['kind'] ) ) : '';
		// phpcs:enable

		$per_page = 30;
		$result   = Run_Repository::query( $per_page, $this->get_pagenum(), $status, $kind );

		$this->set_pagination_args(
			[
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			]
		);

		$this->items           = $result['rows'];
		$this->_column_headers = [ $this->get_columns(), [], [] ];
	}

	public function no_items(): void {
		esc_html_e( 'Nothing has run yet.', 'eikvakt-backup-manager' );
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter only.
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$base    = admin_url( 'admin.php?page=' . Admin::MENU_REPORTS );
		$out     = [];

		foreach ( [ '', Run_Repository::STATUS_SUCCESS, Run_Repository::STATUS_WARNING, Run_Repository::STATUS_FAILED ] as $status ) {
			$out[ '' === $status ? 'all' : $status ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( '' === $status ? $base : add_query_arg( 'status', $status, $base ) ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( '' === $status ? __( 'All', 'eikvakt-backup-manager' ) : Run_Repository::status_label( $status ) )
			);
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $item Run row.
	 */
	protected function column_started_at( $item ): string {
		return esc_html( Util::datetime( (int) strtotime( $item['started_at'] . ' UTC' ) ) );
	}

	/**
	 * @param array<string, mixed> $item Run row.
	 */
	protected function column_kind( $item ): string {
		$out = '<strong>' . esc_html( Run_Repository::kind_label( (string) $item['kind'] ) ) . '</strong>';
		if ( '' !== (string) $item['backup_type'] ) {
			$out .= ' ' . Admin::badge( Admin::type_label( (string) $item['backup_type'] ), Admin::type_color( (string) $item['backup_type'] ) );
		}
		$out .= '<br><span class="ekbm-muted">' . esc_html( Run_Repository::trigger_label( (string) $item['run_trigger'] ) ) . '</span>';
		return $out;
	}

	/**
	 * @param array<string, mixed> $item Run row.
	 */
	protected function column_status( $item ): string {
		$out = Admin::badge( Run_Repository::status_label( (string) $item['status'] ), Run_Repository::status_color( (string) $item['status'] ) );

		$destinations = json_decode( (string) $item['destinations'], true );
		if ( is_array( $destinations ) && [] !== $destinations ) {
			$parts = [];
			foreach ( $destinations as $id => $outcome ) {
				$parts[] = sprintf(
					'<span style="color:%s;">%s</span>',
					'done' === $outcome ? '#00a32a' : '#d63638',
					esc_html( Destinations::name( (string) $id ) )
				);
			}
			$out .= '<br>' . implode( ', ', $parts );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $item Run row.
	 */
	protected function column_components( $item ): string {
		$components = array_filter( explode( ',', (string) $item['components'] ) );
		$out        = esc_html( implode( ', ', array_map( [ Components::class, 'short_label' ], $components ) ) );

		if ( (int) $item['table_count'] > 0 ) {
			/* translators: %d: number of tables */
			$out .= '<br><span class="ekbm-muted">' . esc_html( sprintf( _n( '%d table', '%d tables', (int) $item['table_count'], 'eikvakt-backup-manager' ), (int) $item['table_count'] ) ) . '</span>';
		}
		if ( (int) $item['file_count'] > 0 ) {
			/* translators: %s: number of files */
			$out .= '<br><span class="ekbm-muted">' . esc_html( sprintf( __( '%s files', 'eikvakt-backup-manager' ), number_format_i18n( (int) $item['file_count'] ) ) ) . '</span>';
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $item Run row.
	 */
	protected function column_size( $item ): string {
		return (int) $item['size_bytes'] > 0 ? esc_html( Util::bytes( (int) $item['size_bytes'] ) ) : '—';
	}

	/**
	 * @param array<string, mixed> $item Run row.
	 */
	protected function column_duration( $item ): string {
		if ( empty( $item['finished_at'] ) ) {
			return '—';
		}
		$seconds = max( 0, (int) strtotime( $item['finished_at'] . ' UTC' ) - (int) strtotime( $item['started_at'] . ' UTC' ) );
		return esc_html( $seconds < 60 ? sprintf( '%ds', $seconds ) : human_time_diff( 0, $seconds ) );
	}

	/**
	 * @param array<string, mixed> $item Run row.
	 */
	protected function column_details( $item ): string {
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( 'run', (int) $item['id'], admin_url( 'admin.php?page=' . Admin::MENU_REPORTS ) ) ),
			esc_html__( 'Details', 'eikvakt-backup-manager' )
		);
	}
}
