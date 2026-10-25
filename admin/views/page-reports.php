<?php
/**
 * Reports: when backups ran, what they contained, whether they succeeded.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects what to display.
$ekbm_run_id = isset( $_GET['run'] ) ? (int) $_GET['run'] : 0;
$ekbm_run    = $ekbm_run_id > 0 ? Run_Repository::get( $ekbm_run_id ) : null;
?>
<div class="wrap ekbm-wrap">
	<?php Admin::header( __( 'Reports', 'eikvakt-backup-manager' ) ); ?>

	<?php if ( null !== $ekbm_run ) : ?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::MENU_REPORTS ) ); ?>">← <?php esc_html_e( 'All reports', 'eikvakt-backup-manager' ); ?></a></p>

		<div class="ekbm-card">
			<table class="widefat striped ekbm-facts">
				<tbody>
					<tr><th><?php esc_html_e( 'Job', 'eikvakt-backup-manager' ); ?></th><td><?php echo esc_html( Run_Repository::kind_label( (string) $ekbm_run['kind'] ) . ' · ' . Run_Repository::trigger_label( (string) $ekbm_run['run_trigger'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Result', 'eikvakt-backup-manager' ); ?></th><td><?php echo Admin::badge( Run_Repository::status_label( (string) $ekbm_run['status'] ), Run_Repository::status_color( (string) $ekbm_run['status'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></td></tr>
					<tr><th><?php esc_html_e( 'Started', 'eikvakt-backup-manager' ); ?></th><td><?php echo esc_html( Util::datetime( (int) strtotime( $ekbm_run['started_at'] . ' UTC' ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Finished', 'eikvakt-backup-manager' ); ?></th><td><?php echo esc_html( empty( $ekbm_run['finished_at'] ) ? '—' : Util::datetime( (int) strtotime( $ekbm_run['finished_at'] . ' UTC' ) ) ); ?></td></tr>
					<?php if ( '' !== (string) $ekbm_run['backup_id'] ) : ?>
						<tr><th><?php esc_html_e( 'Backup', 'eikvakt-backup-manager' ); ?></th><td><code><?php echo esc_html( (string) $ekbm_run['backup_id'] ); ?></code> <?php echo '' !== (string) $ekbm_run['backup_type'] ? Admin::badge( Admin::type_label( (string) $ekbm_run['backup_type'] ), Admin::type_color( (string) $ekbm_run['backup_type'] ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></td></tr>
					<?php endif; ?>
					<tr><th><?php esc_html_e( 'Included', 'eikvakt-backup-manager' ); ?></th><td><?php echo esc_html( implode( ', ', array_map( [ Components::class, 'label' ], array_filter( explode( ',', (string) $ekbm_run['components'] ) ) ) ) ?: '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Files / tables', 'eikvakt-backup-manager' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $ekbm_run['file_count'] ) . ' / ' . number_format_i18n( (int) $ekbm_run['table_count'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Size', 'eikvakt-backup-manager' ); ?></th><td><?php echo esc_html( (int) $ekbm_run['size_bytes'] > 0 ? Util::bytes( (int) $ekbm_run['size_bytes'] ) : '—' ); ?></td></tr>
					<?php
					$ekbm_dest = json_decode( (string) $ekbm_run['destinations'], true );
					if ( is_array( $ekbm_dest ) && [] !== $ekbm_dest ) :
						?>
						<tr><th><?php esc_html_e( 'Stored on', 'eikvakt-backup-manager' ); ?></th><td>
							<?php foreach ( $ekbm_dest as $ekbm_dest_id => $ekbm_outcome ) : ?>
								<span style="color:<?php echo esc_attr( 'done' === $ekbm_outcome ? '#00a32a' : '#d63638' ); ?>;"><?php echo esc_html( Destinations::name( (string) $ekbm_dest_id ) . ( 'done' === $ekbm_outcome ? ' ✓' : ' ✗' ) ); ?></span>&nbsp;
							<?php endforeach; ?>
						</td></tr>
					<?php endif; ?>
					<?php if ( '' !== trim( (string) $ekbm_run['summary'] ) ) : ?>
						<tr><th><?php esc_html_e( 'Summary', 'eikvakt-backup-manager' ); ?></th><td><?php echo nl2br( esc_html( (string) $ekbm_run['summary'] ) ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<h2><?php esc_html_e( 'Log', 'eikvakt-backup-manager' ); ?></h2>
		<pre class="ekbm-log"><?php echo esc_html( (string) $ekbm_run['log'] ); ?></pre>
	<?php else : ?>
		<?php
		$ekbm_table = new Runs_List_Table();
		$ekbm_table->prepare_items();
		$ekbm_table->views();
		$ekbm_table->display();
		?>
	<?php endif; ?>
</div>
