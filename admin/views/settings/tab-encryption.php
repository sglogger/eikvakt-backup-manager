<?php
/**
 * Settings → Encryption.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ekbm_enc    = Settings::get( Installer::OPTION_ENCRYPTION );
$ekbm_stored = '' !== (string) $ekbm_enc['passphrase'];
?>
<?php if ( ! Encryptor::is_available() ) : ?>
	<div class="notice notice-error inline"><p><?php esc_html_e( 'Encryption is not available on this server (libsodium missing).', 'eikvakt-backup-manager' ); ?></p></div>
<?php endif; ?>

<div class="notice notice-warning inline">
	<p><strong><?php esc_html_e( 'Write the passphrase down and keep it somewhere safe, away from this site.', 'eikvakt-backup-manager' ); ?></strong>
	<?php esc_html_e( 'An encrypted backup cannot be restored without it — not by you, not by your host, not by anyone. That is the point.', 'eikvakt-backup-manager' ); ?></p>
</div>

<?php Admin::form_open( 'save_encryption' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Encrypt backups', 'eikvakt-backup-manager' ); ?></th>
			<td>
				<label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $ekbm_enc['enabled'] ) ); ?>> <?php esc_html_e( 'Encrypt the database in every new backup', 'eikvakt-backup-manager' ); ?></label><br>
				<label><input type="checkbox" name="files" value="1" <?php checked( ! empty( $ekbm_enc['files'] ) ); ?>> <?php esc_html_e( 'Also encrypt the file archives', 'eikvakt-backup-manager' ); ?></label>
				<p class="description"><?php esc_html_e( 'XChaCha20-Poly1305 with a key derived from your passphrase (PBKDF2-SHA256, 600 000 rounds). Tampering is detected. Existing backups are not changed.', 'eikvakt-backup-manager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="ekbm-pass"><?php esc_html_e( 'Passphrase', 'eikvakt-backup-manager' ); ?></label></th>
			<td>
				<input type="password" id="ekbm-pass" name="passphrase" class="regular-text" autocomplete="new-password" <?php echo $ekbm_stored ? 'placeholder="' . esc_attr__( 'Stored — leave empty to keep', 'eikvakt-backup-manager' ) . '"' : ''; ?>><br>
				<input type="password" name="passphrase_confirm" class="regular-text" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Repeat passphrase', 'eikvakt-backup-manager' ); ?>">
				<p class="description"><?php esc_html_e( 'At least 12 characters. It is stored sealed with this site\'s secret keys so scheduled backups can run; a copy of the database alone does not reveal it. Changing it affects new backups only — older ones keep their passphrase.', 'eikvakt-backup-manager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="ekbm-hint"><?php esc_html_e( 'Reminder', 'eikvakt-backup-manager' ); ?></label></th>
			<td><input type="text" id="ekbm-hint" name="hint" class="regular-text" value="<?php echo esc_attr( (string) $ekbm_enc['hint'] ); ?>">
				<p class="description"><?php esc_html_e( 'Where you keep the passphrase, e.g. "company password manager". Never the passphrase itself.', 'eikvakt-backup-manager' ); ?></p></td>
		</tr>
	</table>
	<?php submit_button(); ?>
</form>
