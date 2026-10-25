<?php
/**
 * Settings → Retention: how many backups to keep, and for how long where.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ekbm_r = Settings::get( Installer::OPTION_RETENTION );

/**
 * Number input.
 *
 * @param string $name  Field.
 * @param int    $min   Minimum.
 * @param int    $max   Maximum.
 */
$ekbm_num = static function ( string $name, int $min, int $max ) use ( $ekbm_r ): void {
	printf(
		'<input type="number" name="%1$s" id="ekbm-%1$s" min="%2$d" max="%3$d" class="small-text" value="%4$d">',
		esc_attr( $name ),
		(int) $min,
		(int) $max,
		(int) $ekbm_r[ $name ]
	);
};
?>
<?php Admin::form_open( 'save_retention' ); ?>
	<p class="description"><?php esc_html_e( 'Old backups are removed automatically after each backup. A full backup is only ever removed together with the incremental backups built on it, and the newest backup of each kind is always kept.', 'eikvakt-backup-manager' ); ?></p>
	<p class="description"><?php esc_html_e( 'Backups marked "Keep" on the Backups screen are exempt from every rule on this page and do not count towards the limits. Marking an incremental backup also keeps the full backup and the incrementals it builds on.', 'eikvakt-backup-manager' ); ?></p>

	<h2><?php esc_html_e( 'How many to keep', 'eikvakt-backup-manager' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="ekbm-keep_chains"><?php esc_html_e( 'File backups', 'eikvakt-backup-manager' ); ?></label></th>
			<td><?php $ekbm_num( 'keep_chains', 1, 365 ); ?> <?php esc_html_e( 'full backups, each with its incremental backups', 'eikvakt-backup-manager' ); ?></td>
		</tr>
		<tr>
			<th scope="row"><label for="ekbm-keep_database"><?php esc_html_e( 'Database backups', 'eikvakt-backup-manager' ); ?></label></th>
			<td><?php $ekbm_num( 'keep_database', 1, 3650 ); ?></td>
		</tr>
		<tr>
			<th scope="row"><label for="ekbm-keep_partial"><?php esc_html_e( 'Partial backups', 'eikvakt-backup-manager' ); ?></label></th>
			<td><?php $ekbm_num( 'keep_partial', 1, 365 ); ?> <span class="description"><?php esc_html_e( 'manual selections and backups before updates', 'eikvakt-backup-manager' ); ?></span></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Rotation (GFS)', 'eikvakt-backup-manager' ); ?></th>
			<td>
				<label><input type="checkbox" name="gfs_enabled" value="1" <?php checked( ! empty( $ekbm_r['gfs_enabled'] ) ); ?>> <?php esc_html_e( 'Instead of a fixed number of file backups, keep one per day, week and month:', 'eikvakt-backup-manager' ); ?></label>
				<p>
					<?php $ekbm_num( 'gfs_daily', 0, 365 ); ?> <?php esc_html_e( 'daily', 'eikvakt-backup-manager' ); ?> ·
					<?php $ekbm_num( 'gfs_weekly', 0, 260 ); ?> <?php esc_html_e( 'weekly', 'eikvakt-backup-manager' ); ?> ·
					<?php $ekbm_num( 'gfs_monthly', 0, 120 ); ?> <?php esc_html_e( 'monthly', 'eikvakt-backup-manager' ); ?>
				</p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'How long to keep them, per location', 'eikvakt-backup-manager' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="ekbm-local_days"><?php esc_html_e( 'On this server', 'eikvakt-backup-manager' ); ?></label></th>
			<td><?php $ekbm_num( 'local_days', 0, 3650 ); ?> <?php esc_html_e( 'days', 'eikvakt-backup-manager' ); ?>
				<p class="description"><?php esc_html_e( 'Backups older than this are removed from this server. Copies on remote destinations are not affected.', 'eikvakt-backup-manager' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><label for="ekbm-remote_days"><?php esc_html_e( 'On remote / cloud destinations', 'eikvakt-backup-manager' ); ?></label></th>
			<td><?php $ekbm_num( 'remote_days', 0, 3650 ); ?> <?php esc_html_e( 'days', 'eikvakt-backup-manager' ); ?>
				<p class="description"><?php esc_html_e( 'Default for every remote destination. Each destination can override it on the Storage tab.', 'eikvakt-backup-manager' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><label for="ekbm-max_age_days"><?php esc_html_e( 'Everywhere', 'eikvakt-backup-manager' ); ?></label></th>
			<td><?php $ekbm_num( 'max_age_days', 0, 3650 ); ?> <?php esc_html_e( 'days maximum age', 'eikvakt-backup-manager' ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Fewer copies on this server', 'eikvakt-backup-manager' ); ?></th>
			<td>
				<?php $ekbm_num( 'local_chains', 0, 365 ); ?> <?php esc_html_e( 'file backups', 'eikvakt-backup-manager' ); ?> ·
				<?php $ekbm_num( 'local_database', 0, 3650 ); ?> <?php esc_html_e( 'database backups', 'eikvakt-backup-manager' ); ?>
				<p class="description"><?php esc_html_e( 'Keep only the newest ones locally to save disk space; older copies stay on the remote destinations. Applies only to backups that also exist remotely.', 'eikvakt-backup-manager' ); ?></p>
			</td>
		</tr>
	</table>
	<p class="description"><?php esc_html_e( '0 means no limit.', 'eikvakt-backup-manager' ); ?></p>

	<p><label><input type="checkbox" name="apply_now" value="1"> <?php esc_html_e( 'Apply the rules right away', 'eikvakt-backup-manager' ); ?></label></p>
	<?php submit_button(); ?>
</form>
