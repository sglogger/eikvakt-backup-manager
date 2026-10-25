<?php
/**
 * Settings → General.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ekbm_settings = Settings::general();
?>
<?php Admin::form_open( 'save_general' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="ekbm-path"><?php esc_html_e( 'Backup directory', 'eikvakt-backup-manager' ); ?></label></th>
			<td>
				<input type="text" id="ekbm-path" name="backup_path" class="large-text code" value="<?php echo esc_attr( (string) $ekbm_settings['backup_path'] ); ?>" placeholder="<?php echo esc_attr( Paths::default_dir() ); ?>">
				<p class="description">
					<?php esc_html_e( 'In use:', 'eikvakt-backup-manager' ); ?> <code><?php echo esc_html( Paths::backup_dir() ); ?></code><br>
					<?php esc_html_e( 'Leave empty for the automatic choice: outside the web root when the server allows it, otherwise a protected, randomly named folder. A directory outside the web root is the safest place.', 'eikvakt-backup-manager' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="ekbm-part"><?php esc_html_e( 'Archive part size', 'eikvakt-backup-manager' ); ?></label></th>
			<td><input type="number" id="ekbm-part" name="part_size_mb" min="10" max="4000" class="small-text" value="<?php echo esc_attr( (string) $ekbm_settings['part_size_mb'] ); ?>"> MB
				<p class="description"><?php esc_html_e( 'Large backups are split into parts of this size, which upload and download more reliably.', 'eikvakt-backup-manager' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Copy on this server', 'eikvakt-backup-manager' ); ?></th>
			<td><label><input type="checkbox" name="keep_local" value="1" <?php checked( ! empty( $ekbm_settings['keep_local'] ) ); ?>> <?php esc_html_e( 'Keep a copy of every backup on this server', 'eikvakt-backup-manager' ); ?></label>
				<p class="description"><?php esc_html_e( 'When off, the local copy is deleted once at least one remote destination has received the backup.', 'eikvakt-backup-manager' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><label for="ekbm-budget"><?php esc_html_e( 'Work per request', 'eikvakt-backup-manager' ); ?></label></th>
			<td><input type="number" id="ekbm-budget" name="time_budget" min="5" max="300" class="small-text" value="<?php echo esc_attr( (string) $ekbm_settings['time_budget'] ); ?>"> <?php esc_html_e( 'seconds', 'eikvakt-backup-manager' ); ?>
				<p class="description"><?php esc_html_e( 'Backups run in short steps so they never hit the server\'s time limit. Raise this on a dedicated server, lower it if backups stall.', 'eikvakt-backup-manager' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'E-mail reports', 'eikvakt-backup-manager' ); ?></th>
			<td>
				<select name="email_mode">
					<option value="failures" <?php selected( 'failures', $ekbm_settings['email_mode'] ); ?>><?php esc_html_e( 'Only when something went wrong', 'eikvakt-backup-manager' ); ?></option>
					<option value="always" <?php selected( 'always', $ekbm_settings['email_mode'] ); ?>><?php esc_html_e( 'After every backup and restore', 'eikvakt-backup-manager' ); ?></option>
					<option value="never" <?php selected( 'never', $ekbm_settings['email_mode'] ); ?>><?php esc_html_e( 'Never', 'eikvakt-backup-manager' ); ?></option>
				</select>
				<p><textarea name="email_recipients" rows="2" class="large-text" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"><?php echo esc_textarea( (string) $ekbm_settings['email_recipients'] ); ?></textarea></p>
				<p class="description"><?php esc_html_e( 'Recipients, separated by commas. Empty: the site administrator address.', 'eikvakt-backup-manager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Uninstall', 'eikvakt-backup-manager' ); ?></th>
			<td>
				<label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( ! empty( $ekbm_settings['delete_data_on_uninstall'] ) ); ?>> <?php esc_html_e( 'Delete settings and reports when the plugin is deleted', 'eikvakt-backup-manager' ); ?></label><br>
				<label><input type="checkbox" name="delete_backups_on_uninstall" value="1" <?php checked( ! empty( $ekbm_settings['delete_backups_on_uninstall'] ) ); ?>> <?php esc_html_e( 'Also delete the backups stored on this server', 'eikvakt-backup-manager' ); ?></label>
			</td>
		</tr>
	</table>
	<?php submit_button(); ?>
</form>

<?php Admin::form_open( 'test_email' ); ?>
	<?php submit_button( __( 'Send a test e-mail', 'eikvakt-backup-manager' ), 'secondary', 'submit', false ); ?>
</form>
