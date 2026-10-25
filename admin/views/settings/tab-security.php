<?php
/**
 * Settings → Security: backup directory exposure and the plugin password.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ekbm_exposure = Paths::exposure();
$ekbm_security = Settings::get( Installer::OPTION_SECURITY );
$ekbm_has_pass = Password_Gate::is_enabled();

$ekbm_states = [
	'outside'   => [ '#00a32a', __( 'Outside the web root — backups cannot be requested by URL at all.', 'eikvakt-backup-manager' ) ],
	'protected' => [ '#00a32a', __( 'Inside the web root, but the web server refuses to serve it.', 'eikvakt-backup-manager' ) ],
	'exposed'   => [ '#d63638', __( 'EXPOSED: the web server hands out files from the backup directory. Act now.', 'eikvakt-backup-manager' ) ],
	'unknown'   => [ '#dba617', __( 'Not verified: the test request could not be made.', 'eikvakt-backup-manager' ) ],
];
$ekbm_state  = $ekbm_states[ $ekbm_exposure['status'] ] ?? $ekbm_states['unknown'];
?>
<h2><?php esc_html_e( 'Backup directory', 'eikvakt-backup-manager' ); ?></h2>
<table class="widefat striped ekbm-facts">
	<tbody>
		<tr><th><?php esc_html_e( 'Location', 'eikvakt-backup-manager' ); ?></th><td><code><?php echo esc_html( Paths::backup_dir() ); ?></code></td></tr>
		<tr><th><?php esc_html_e( 'Reachable from the internet?', 'eikvakt-backup-manager' ); ?></th><td><strong style="color:<?php echo esc_attr( $ekbm_state[0] ); ?>;"><?php echo esc_html( $ekbm_state[1] ); ?></strong>
			<?php if ( $ekbm_exposure['checked'] > 0 ) : ?>
				<br><small><?php /* translators: %s: date and time */ echo esc_html( sprintf( __( 'Tested %s. Re-tested daily.', 'eikvakt-backup-manager' ), Util::datetime( $ekbm_exposure['checked'] ) ) ); ?></small>
			<?php endif; ?>
		</td></tr>
		<tr><th><?php esc_html_e( 'Protection', 'eikvakt-backup-manager' ); ?></th><td><?php esc_html_e( 'Random, unguessable directory and file names; deny-all rules for Apache, LiteSpeed and IIS; no directory listing; downloads only through this screen while signed in.', 'eikvakt-backup-manager' ); ?></td></tr>
	</tbody>
</table>

<?php if ( 'exposed' === $ekbm_exposure['status'] || 'unknown' === $ekbm_exposure['status'] ) : ?>
	<div class="ekbm-card">
		<h3><?php esc_html_e( 'How to fix it', 'eikvakt-backup-manager' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Best: choose a backup directory outside the web root under Settings → General (ask your host which path is not served).', 'eikvakt-backup-manager' ); ?></li>
			<li><?php esc_html_e( 'On nginx, which ignores .htaccess files, add this block to the server configuration and reload nginx:', 'eikvakt-backup-manager' ); ?>
				<pre class="ekbm-code"><?php echo esc_html( Paths::nginx_rule() ); ?></pre></li>
		</ol>
	</div>
<?php endif; ?>

<?php Admin::form_open( 'check_exposure' ); ?>
	<?php submit_button( __( 'Test again', 'eikvakt-backup-manager' ), 'secondary', 'submit', false ); ?>
</form>

<h2><?php esc_html_e( 'Plugin password', 'eikvakt-backup-manager' ); ?></h2>
<p class="description"><?php esc_html_e( 'An extra password for every Backup Manager screen, on top of administrator rights. Useful when several people administer the site: backups contain everything, including every user\'s data.', 'eikvakt-backup-manager' ); ?></p>

<?php Admin::form_open( 'save_security' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Status', 'eikvakt-backup-manager' ); ?></th>
			<td><?php echo $ekbm_has_pass ? Admin::badge( __( 'Password set', 'eikvakt-backup-manager' ), '#00a32a' ) : Admin::badge( __( 'No password', 'eikvakt-backup-manager' ), '#646970' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></td>
		</tr>
		<?php if ( $ekbm_has_pass ) : ?>
			<tr>
				<th scope="row"><label for="ekbm-current"><?php esc_html_e( 'Current password', 'eikvakt-backup-manager' ); ?></label></th>
				<td><input type="password" id="ekbm-current" name="current_password" class="regular-text" autocomplete="current-password"></td>
			</tr>
		<?php endif; ?>
		<tr>
			<th scope="row"><label for="ekbm-new"><?php echo esc_html( $ekbm_has_pass ? __( 'New password', 'eikvakt-backup-manager' ) : __( 'Password', 'eikvakt-backup-manager' ) ); ?></label></th>
			<td>
				<input type="password" id="ekbm-new" name="new_password" class="regular-text" autocomplete="new-password"><br>
				<input type="password" name="new_password_confirm" class="regular-text" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Repeat password', 'eikvakt-backup-manager' ); ?>">
				<p class="description"><?php esc_html_e( 'At least 10 characters.', 'eikvakt-backup-manager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="ekbm-session"><?php esc_html_e( 'Stay unlocked for', 'eikvakt-backup-manager' ); ?></label></th>
			<td><input type="number" id="ekbm-session" name="session_minutes" min="5" max="720" class="small-text" value="<?php echo esc_attr( (string) $ekbm_security['session_minutes'] ); ?>"> <?php esc_html_e( 'minutes', 'eikvakt-backup-manager' ); ?></td>
		</tr>
		<?php if ( $ekbm_has_pass ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Remove', 'eikvakt-backup-manager' ); ?></th>
				<td><label><input type="checkbox" name="disable_password" value="1"> <?php esc_html_e( 'Remove the password', 'eikvakt-backup-manager' ); ?></label></td>
			</tr>
		<?php endif; ?>
	</table>
	<p class="description"><?php echo wp_kses( __( 'Forgot it? Add <code>define( \'EKBM_DISABLE_PASSWORD\', true );</code> to wp-config.php, set a new password here, then remove the line again.', 'eikvakt-backup-manager' ), [ 'code' => [] ] ); ?></p>
	<?php submit_button(); ?>
</form>
