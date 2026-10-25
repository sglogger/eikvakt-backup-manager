<?php
/**
 * The backup list.
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

final class Backups_List_Table extends \WP_List_Table {

	public function __construct() {
		parent::__construct(
			[
				'singular' => 'ekbm-backup',
				'plural'   => 'ekbm-backups',
				'ajax'     => false,
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'created'    => __( 'Date', 'eikvakt-backup-manager' ),
			'type'       => __( 'Type', 'eikvakt-backup-manager' ),
			'components' => __( 'Contents', 'eikvakt-backup-manager' ),
			'size'       => __( 'Size', 'eikvakt-backup-manager' ),
			'locations'  => __( 'Stored on', 'eikvakt-backup-manager' ),
			'actions'    => __( 'Actions', 'eikvakt-backup-manager' ),
		];
	}

	public function prepare_items(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter only.
		$filter = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( (string) $_GET['type'] ) ) : '';

		$items = [];
		foreach ( Catalog::all() as $manifest ) {
			if ( 'kept' === $filter ) {
				if ( ! $manifest->is_kept() ) {
					continue;
				}
			} elseif ( '' !== $filter && $manifest->type() !== $filter ) {
				continue;
			}
			$items[] = $manifest;
		}

		$per_page = 25;
		$page     = $this->get_pagenum();

		$this->set_pagination_args(
			[
				'total_items' => count( $items ),
				'per_page'    => $per_page,
			]
		);

		$this->items           = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );
		$this->_column_headers = [ $this->get_columns(), [], [] ];
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter only.
		$current = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( (string) $_GET['type'] ) ) : '';
		$base    = admin_url( 'admin.php?page=' . Admin::MENU_BACKUPS );
		$views   = [
			''                         => __( 'All', 'eikvakt-backup-manager' ),
			Manifest::TYPE_FULL        => Admin::type_label( Manifest::TYPE_FULL ),
			Manifest::TYPE_INCREMENTAL => Admin::type_label( Manifest::TYPE_INCREMENTAL ),
			Manifest::TYPE_DATABASE    => Admin::type_label( Manifest::TYPE_DATABASE ),
			Manifest::TYPE_PARTIAL     => Admin::type_label( Manifest::TYPE_PARTIAL ),
			'kept'                     => __( 'Kept', 'eikvakt-backup-manager' ),
		];

		$out = [];
		foreach ( $views as $type => $label ) {
			$out[ '' === $type ? 'all' : $type ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( '' === $type ? $base : add_query_arg( 'type', $type, $base ) ),
				$current === $type ? ' class="current" aria-current="page"' : '',
				esc_html( $label )
			);
		}
		return $out;
	}

	public function no_items(): void {
		esc_html_e( 'No backups yet.', 'eikvakt-backup-manager' );
	}

	/**
	 * @param Manifest $item Backup.
	 */
	protected function column_created( $item ): string {
		$out = '<strong>' . esc_html( Util::datetime( $item->created() ) ) . '</strong>';

		$label = (string) $item->data['label'];
		if ( '' !== $label ) {
			$out .= '<br>' . esc_html( $label );
		}

		$out .= '<br><code class="ekbm-id">' . esc_html( $item->id() ) . '</code>';

		if ( $item->site( 'home' ) !== home_url() ) {
			/* translators: %s: URL of the site the backup comes from */
			$out .= '<br><span class="ekbm-muted">' . esc_html( sprintf( __( 'From %s', 'eikvakt-backup-manager' ), $item->site( 'home' ) ) ) . '</span>';
		}

		return $out;
	}

	/**
	 * @param Manifest $item Backup.
	 */
	protected function column_type( $item ): string {
		$out = Admin::badge( Admin::type_label( $item->type() ), Admin::type_color( $item->type() ) );

		if ( $item->is_kept() ) {
			$out .= ' ' . Admin::badge( __( 'Keep', 'eikvakt-backup-manager' ), '#8a2be2' );
		} elseif ( $this->protected_by_chain( $item ) ) {
			$out .= '<br><span class="ekbm-muted"><span class="dashicons dashicons-star-half"></span> ' . esc_html__( 'Kept with its chain (a backup in it is marked)', 'eikvakt-backup-manager' ) . '</span>';
		}

		if ( $item->is_encrypted() ) {
			$out .= ' <span class="dashicons dashicons-lock" title="' . esc_attr__( 'Encrypted', 'eikvakt-backup-manager' ) . '"></span>';
		}

		$out .= '<br><span class="ekbm-muted">' . esc_html( Run_Repository::trigger_label( (string) $item->data['trigger'] ) ) . '</span>';

		return $out;
	}

	/**
	 * Not marked itself, but part of a chain with a marked member — and
	 * therefore protected from retention and manual deletion as well.
	 */
	private function protected_by_chain( Manifest $item ): bool {
		if ( '' === $item->chain() ) {
			return false;
		}
		foreach ( (array) $this->items as $other ) {
			if ( $other->chain() === $item->chain() && $other->is_kept() ) {
				return true;
			}
		}
		return [] !== Catalog::kept_among( Catalog::dependents( $item->id() ) );
	}

	/**
	 * @param Manifest $item Backup.
	 */
	protected function column_components( $item ): string {
		$labels = array_map( [ Components::class, 'short_label' ], $item->components() );
		$out    = esc_html( implode( ', ', $labels ) );

		if ( Manifest::TYPE_INCREMENTAL === $item->type() ) {
			$out .= '<br><span class="ekbm-muted">' . esc_html(
				sprintf(
					/* translators: 1: changed files, 2: deleted files */
					__( '%1$s changed, %2$s deleted', 'eikvakt-backup-manager' ),
					number_format_i18n( $item->stat( 'changed' ) ),
					number_format_i18n( $item->stat( 'deleted' ) )
				)
			) . '</span>';
		} elseif ( $item->stat( 'files' ) > 0 ) {
			/* translators: %s: number of files */
			$out .= '<br><span class="ekbm-muted">' . esc_html( sprintf( __( '%s files', 'eikvakt-backup-manager' ), number_format_i18n( $item->stat( 'files' ) ) ) ) . '</span>';
		}

		if ( [] !== $item->tables() ) {
			/* translators: %d: number of tables */
			$out .= '<br><span class="ekbm-muted">' . esc_html( sprintf( _n( '%d table', '%d tables', count( $item->tables() ), 'eikvakt-backup-manager' ), count( $item->tables() ) ) ) . '</span>';
		}

		return $out;
	}

	/**
	 * @param Manifest $item Backup.
	 */
	protected function column_size( $item ): string {
		return esc_html( Util::bytes( $item->total_size() ) );
	}

	/**
	 * @param Manifest $item Backup.
	 */
	protected function column_locations( $item ): string {
		$names = array_map( [ Destinations::class, 'name' ], Catalog::locations( $item->id() ) );
		return esc_html( implode( ', ', $names ) );
	}

	/**
	 * @param Manifest $item Backup.
	 */
	protected function column_actions( $item ): string {
		$restore = add_query_arg(
			[
				'page'   => Admin::MENU_RESTORE,
				'backup' => $item->id(),
			],
			admin_url( 'admin.php' )
		);

		$out = sprintf( '<a class="button button-primary" href="%s">%s</a> ', esc_url( $restore ), esc_html__( 'Restore', 'eikvakt-backup-manager' ) );

		if ( Catalog::local_files_present( $item ) ) {
			$out .= '<details class="ekbm-downloads"><summary class="button">' . esc_html__( 'Download', 'eikvakt-backup-manager' ) . '</summary><ul>';
			foreach ( $item->all_file_names() as $file ) {
				$out .= sprintf( '<li><a href="%s">%s</a></li>', esc_url( Admin::download_url( $item->id(), $file ) ), esc_html( substr( $file, strlen( $item->id() ) + 1 ) ) );
			}
			$out .= '</ul></details> ';
		}

		ob_start();
		Admin::form_open( 'toggle_keep', Admin::MENU_BACKUPS, [ 'class' => 'ekbm-inline' ] );
		printf( '<input type="hidden" name="backup_id" value="%s">', esc_attr( $item->id() ) );
		printf( '<input type="hidden" name="keep" value="%s">', $item->is_kept() ? '' : '1' );
		printf(
			'<button type="submit" class="button" title="%s"><span class="dashicons dashicons-%s"></span> %s</button> ',
			esc_attr__( 'Kept backups are never deleted by the retention rules.', 'eikvakt-backup-manager' ),
			$item->is_kept() ? 'star-filled' : 'star-empty',
			esc_html( $item->is_kept() ? __( 'Unkeep', 'eikvakt-backup-manager' ) : __( 'Keep', 'eikvakt-backup-manager' ) )
		);
		echo '</form>';
		$out .= (string) ob_get_clean();

		ob_start();
		Admin::form_open( 'delete_backup', Admin::MENU_BACKUPS, [ 'class' => 'ekbm-inline ekbm-confirm-delete' ] );
		printf( '<input type="hidden" name="backup_id" value="%s">', esc_attr( $item->id() ) );
		printf( '<button type="submit" class="button ekbm-button-delete">%s</button>', esc_html__( 'Delete', 'eikvakt-backup-manager' ) );
		echo '</form>';
		$out .= (string) ob_get_clean();

		$dependents = Catalog::dependents( $item->id() );
		if ( [] !== $dependents ) {
			/* translators: %d: number of incremental backups */
			$out .= '<br><span class="ekbm-muted">' . esc_html( sprintf( _n( 'Deleting also removes %d later incremental backup.', 'Deleting also removes %d later incremental backups.', count( $dependents ), 'eikvakt-backup-manager' ), count( $dependents ) ) ) . '</span>';
		}

		return $out;
	}
}
