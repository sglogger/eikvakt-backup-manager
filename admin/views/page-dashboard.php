<?php
/**
 * Dashboard: status at a glance, one-click setup, backup now, live progress.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ekbm_schedule   = Settings::get( Installer::OPTION_SCHEDULE );
$ekbm_status     = Job_Runner::status();
$ekbm_latest     = Catalog::latest();
$ekbm_last_run   = Run_Repository::last( 'backup' );
$ekbm_exposure   = Paths::exposure();
$ekbm_sets       = Catalog::all();
$ekbm_remote     = Destinations::enabled();
$ekbm_next_files = Scheduler::next( 'files' );
$ekbm_next_db    = Scheduler::next( 'database' );

/**
 * One status tile.
 *
 * @param string $label  Caption.
 * @param string $value  Main value.
 * @param string $detail Second line.
 * @param string $state  ok|warn|bad|info.
 * @param string $icon   Dashicon name.
 */
$ekbm_tile = static function ( string $label, string $value, string $detail, string $state, string $icon ): void {
	printf(
		'<div class="ekbm-tile ekbm-tile-%1$s"><span class="dashicons dashicons-%2$s"></span><div><div class="ekbm-tile-label">%3$s</div><div class="ekbm-tile-value">%4$s</div><div class="ekbm-tile-detail">%5$s</div></div></div>',
		esc_attr( $state ),
		esc_attr( $icon ),
		esc_html( $label ),
		esc_html( $value ),
		esc_html( $detail )
	);
};
?>
<div class="wrap ekbm-wrap">
	<?php Admin::header( __( 'Backup Manager', 'eikvakt-backup-manager' ) ); ?>

	<?php if ( ! Settings::is_configured() ) : ?>
		<div class="ekbm-card ekbm-setup">
			<h2><?php esc_html_e( 'Set up backups in one click', 'eikvakt-backup-manager' ); ?></h2>
			<p><?php esc_html_e( 'This applies safe defaults and starts your first backup right away. Everything can be changed later under Settings.', 'eikvakt-backup-manager' ); ?></p>
			<ul class="ekbm-checklist">
				<li><?php esc_html_e( 'Files: daily at 02:00, incremental — only changed files are saved; a new full backup every 7th day', 'eikvakt-backup-manager' ); ?></li>
				<li><?php esc_html_e( 'Database: daily at 03:00', 'eikvakt-backup-manager' ); ?></li>
				<li><?php esc_html_e( 'Automatic backup before every WordPress, plugin and theme update', 'eikvakt-backup-manager' ); ?></li>
				<li><?php esc_html_e( 'Keep 4 weeks of file backups and 14 database backups', 'eikvakt-backup-manager' ); ?></li>
				<li><?php esc_html_e( 'Stored in a protected directory on this server — add Dropbox, OneDrive, FTP or SFTP at any time', 'eikvakt-backup-manager' ); ?></li>
				<li><?php esc_html_e( 'E-mail to the site administrator if a backup fails', 'eikvakt-backup-manager' ); ?></li>
			</ul>
			<?php Admin::form_open( 'run_setup', Admin::MENU_DASHBOARD ); ?>
				<?php submit_button( __( 'Set up and run the first backup', 'eikvakt-backup-manager' ), 'primary hero', 'submit', false ); ?>
			</form>
		</div>
	<?php endif; ?>

	<div id="ekbm-progress" class="ekbm-card ekbm-progress" <?php echo $ekbm_status['running'] || $ekbm_status['queued'] ? '' : 'hidden'; ?>>
		<div class="ekbm-progress-head">
			<strong id="ekbm-progress-title"><?php esc_html_e( 'Running', 'eikvakt-backup-manager' ); ?></strong>
			<span id="ekbm-progress-message" class="ekbm-muted"><?php echo esc_html( (string) ( $ekbm_status['job']['message'] ?? '' ) ); ?></span>
		</div>
		<div class="ekbm-bar"><div id="ekbm-progress-bar" style="width:<?php echo (int) ( $ekbm_status['job']['progress'] ?? 0 ); ?>%;"></div></div>
		<?php Admin::form_open( 'cancel_job', Admin::MENU_DASHBOARD, [ 'class' => 'ekbm-inline' ] ); ?>
			<button type="submit" class="button ekbm-button-delete"><?php esc_html_e( 'Cancel', 'eikvakt-backup-manager' ); ?></button>
		</form>
	</div>

	<?php if ( ! $ekbm_status['running'] && null !== $ekbm_status['last'] && $ekbm_status['last']['finished'] > time() - HOUR_IN_SECONDS ) : ?>
		<?php
		$ekbm_last_status = (string) $ekbm_status['last']['status'];
		$ekbm_class       = Run_Repository::STATUS_SUCCESS === $ekbm_last_status ? 'success' : ( Run_Repository::STATUS_WARNING === $ekbm_last_status ? 'warning' : 'error' );
		?>
		<div class="notice notice-<?php echo esc_attr( $ekbm_class ); ?> inline">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: job kind, 2: result */
						__( 'Last job (%1$s): %2$s.', 'eikvakt-backup-manager' ),
						Run_Repository::kind_label( (string) $ekbm_status['last']['type'] ),
						Run_Repository::status_label( $ekbm_last_status )
					)
				);
				echo ' ' . esc_html( (string) ( $ekbm_status['last']['error'] ?: $ekbm_status['last']['message'] ) );
				?>
				<a href="<?php echo esc_url( add_query_arg( 'run', (int) $ekbm_status['last']['run_id'], admin_url( 'admin.php?page=' . Admin::MENU_REPORTS ) ) ); ?>"><?php esc_html_e( 'Report', 'eikvakt-backup-manager' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<div class="ekbm-tiles">
		<?php
		if ( null === $ekbm_latest ) {
			$ekbm_tile( __( 'Last backup', 'eikvakt-backup-manager' ), __( 'None yet', 'eikvakt-backup-manager' ), __( 'Run your first backup now.', 'eikvakt-backup-manager' ), 'bad', 'backup' );
		} else {
			$ekbm_age = time() - $ekbm_latest->created();
			$ekbm_tile(
				__( 'Last backup', 'eikvakt-backup-manager' ),
				/* translators: %s: human time difference */
				sprintf( __( '%s ago', 'eikvakt-backup-manager' ), human_time_diff( $ekbm_latest->created() ) ),
				Admin::type_label( $ekbm_latest->type() ) . ' · ' . Util::bytes( $ekbm_latest->total_size() ),
				$ekbm_age > 3 * DAY_IN_SECONDS ? 'warn' : 'ok',
				'backup'
			);
		}

		if ( null !== $ekbm_last_run && Run_Repository::STATUS_FAILED === $ekbm_last_run['status'] ) {
			$ekbm_tile( __( 'Last run', 'eikvakt-backup-manager' ), __( 'Failed', 'eikvakt-backup-manager' ), (string) $ekbm_last_run['summary'], 'bad', 'warning' );
		}

		$ekbm_tile(
			__( 'Next file backup', 'eikvakt-backup-manager' ),
			null !== $ekbm_next_files ? Util::datetime( $ekbm_next_files ) : __( 'Not scheduled', 'eikvakt-backup-manager' ),
			Scheduler::describe( (array) $ekbm_schedule['files'] ),
			null !== $ekbm_next_files ? 'info' : 'warn',
			'calendar-alt'
		);

		$ekbm_tile(
			__( 'Next database backup', 'eikvakt-backup-manager' ),
			null !== $ekbm_next_db ? Util::datetime( $ekbm_next_db ) : __( 'Not scheduled', 'eikvakt-backup-manager' ),
			Scheduler::describe( (array) $ekbm_schedule['database'] ),
			null !== $ekbm_next_db ? 'info' : 'warn',
			'database'
		);

		$ekbm_tile(
			__( 'Stored backups', 'eikvakt-backup-manager' ),
			number_format_i18n( count( $ekbm_sets ) ),
			/* translators: %s: disk space */
			sprintf( __( '%s on this server', 'eikvakt-backup-manager' ), Util::bytes( Catalog::local_usage() ) ),
			'info',
			'portfolio'
		);

		$ekbm_tile(
			__( 'Destinations', 'eikvakt-backup-manager' ),
			/* translators: %d: number of remote destinations */
			sprintf( _n( 'This server + %d', 'This server + %d', count( $ekbm_remote ), 'eikvakt-backup-manager' ), count( $ekbm_remote ) ),
			[] === $ekbm_remote ? __( 'Add an off-site copy for real safety.', 'eikvakt-backup-manager' ) : implode( ', ', array_column( $ekbm_remote, 'name' ) ),
			[] === $ekbm_remote ? 'warn' : 'ok',
			'cloud'
		);

		$ekbm_exposure_labels = [
			'outside'   => [ __( 'Outside the web root', 'eikvakt-backup-manager' ), 'ok' ],
			'protected' => [ __( 'Protected', 'eikvakt-backup-manager' ), 'ok' ],
			'exposed'   => [ __( 'EXPOSED', 'eikvakt-backup-manager' ), 'bad' ],
			'unknown'   => [ __( 'Not verified', 'eikvakt-backup-manager' ), 'warn' ],
		];
		$ekbm_exp             = $ekbm_exposure_labels[ $ekbm_exposure['status'] ] ?? $ekbm_exposure_labels['unknown'];
		$ekbm_tile(
			__( 'Backup directory', 'eikvakt-backup-manager' ),
			$ekbm_exp[0],
			$ekbm_exposure['checked'] > 0
				/* translators: %s: human time difference */
				? sprintf( __( 'Tested %s ago', 'eikvakt-backup-manager' ), human_time_diff( $ekbm_exposure['checked'] ) )
				: __( 'Test it on the Security tab.', 'eikvakt-backup-manager' ),
			$ekbm_exp[1],
			'shield'
		);
		?>
	</div>

	<div class="ekbm-columns">
		<div class="ekbm-card">
			<h2><?php esc_html_e( 'Back up now', 'eikvakt-backup-manager' ); ?></h2>
			<?php Admin::form_open( 'backup_now', Admin::MENU_DASHBOARD ); ?>
				<fieldset class="ekbm-components">
					<?php foreach ( Components::ALL as $ekbm_component ) : ?>
						<label><input type="checkbox" name="components[]" value="<?php echo esc_attr( $ekbm_component ); ?>" checked> <?php echo esc_html( Components::label( $ekbm_component ) ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<p>
					<label for="ekbm-label"><?php esc_html_e( 'Note (optional)', 'eikvakt-backup-manager' ); ?></label><br>
					<input type="text" id="ekbm-label" name="label" class="regular-text" maxlength="120">
				</p>
				<p class="description"><?php esc_html_e( 'Everything selected: a complete backup that also serves as a migration package. Only the file parts selected: continues the incremental chain. Anything else: a partial backup.', 'eikvakt-backup-manager' ); ?></p>
				<?php submit_button( __( 'Start backup', 'eikvakt-backup-manager' ), 'primary', 'submit', false, $ekbm_status['running'] ? [ 'disabled' => 'disabled' ] : [] ); ?>
			</form>
		</div>

		<div class="ekbm-card">
			<h2><?php esc_html_e( 'Recent activity', 'eikvakt-backup-manager' ); ?></h2>
			<?php $ekbm_recent = Run_Repository::query( 6, 1 )['rows']; ?>
			<?php if ( [] === $ekbm_recent ) : ?>
				<p class="ekbm-muted"><?php esc_html_e( 'Nothing has run yet.', 'eikvakt-backup-manager' ); ?></p>
			<?php else : ?>
				<table class="widefat striped ekbm-compact">
					<tbody>
					<?php foreach ( $ekbm_recent as $ekbm_run ) : ?>
						<tr>
							<td><?php echo esc_html( Util::datetime( (int) strtotime( $ekbm_run['started_at'] . ' UTC' ) ) ); ?></td>
							<td><?php echo esc_html( Run_Repository::kind_label( (string) $ekbm_run['kind'] ) ); ?><br><span class="ekbm-muted"><?php echo esc_html( Run_Repository::trigger_label( (string) $ekbm_run['run_trigger'] ) ); ?></span></td>
							<td><?php echo Admin::badge( Run_Repository::status_label( (string) $ekbm_run['status'] ), Run_Repository::status_color( (string) $ekbm_run['status'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></td>
							<td><a href="<?php echo esc_url( add_query_arg( 'run', (int) $ekbm_run['id'], admin_url( 'admin.php?page=' . Admin::MENU_REPORTS ) ) ); ?>"><?php esc_html_e( 'Details', 'eikvakt-backup-manager' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::MENU_REPORTS ) ); ?>"><?php esc_html_e( 'All reports', 'eikvakt-backup-manager' ); ?> →</a></p>
			<?php endif; ?>
		</div>
	</div>
</div>
