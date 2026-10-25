<?php
/**
 * Migration: move or copy this site to another domain or host.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ekbm_new_key = get_transient( 'ekbm_new_key_' . get_current_user_id() );
if ( is_string( $ekbm_new_key ) ) {
	delete_transient( 'ekbm_new_key_' . get_current_user_id() );
	$ekbm_new_key = Secret_Cipher::decrypt( $ekbm_new_key );
}

$ekbm_packages = array_values(
	array_filter(
		Catalog::all(),
		static fn( Manifest $m ) => $m->is_complete() && Catalog::local_files_present( $m ) && $m->site( 'home' ) === home_url()
	)
);
$ekbm_keys     = Migration::keys();
?>
<div class="wrap ekbm-wrap">
	<?php Admin::header( __( 'Migration', 'eikvakt-backup-manager' ) ); ?>

	<p class="description"><?php esc_html_e( 'Move or copy this site to a new domain or host — completely, or only selected parts such as the uploads or the database. Install Eikvakt Backup Manager on the target site, then follow the steps below.', 'eikvakt-backup-manager' ); ?></p>

	<?php if ( is_string( $ekbm_new_key ) && '' !== $ekbm_new_key ) : ?>
		<div class="ekbm-card ekbm-key">
			<h2><?php esc_html_e( 'Your migration key', 'eikvakt-backup-manager' ); ?></h2>
			<p><?php esc_html_e( 'Paste it into Migration → Import on the target site within 24 hours. Anyone holding this key can download this backup, so treat it like a password.', 'eikvakt-backup-manager' ); ?></p>
			<textarea class="large-text code" rows="3" readonly id="ekbm-key-text"><?php echo esc_textarea( $ekbm_new_key ); ?></textarea>
			<p><button type="button" class="button ekbm-copy" data-target="#ekbm-key-text"><?php esc_html_e( 'Copy', 'eikvakt-backup-manager' ); ?></button></p>
		</div>
	<?php endif; ?>

	<div class="ekbm-columns">
		<div class="ekbm-card">
			<h2><span class="ekbm-step">1</span> <?php esc_html_e( 'Export from this site', 'eikvakt-backup-manager' ); ?></h2>
			<p><?php esc_html_e( 'Create a complete backup (files and database), then a migration key for it.', 'eikvakt-backup-manager' ); ?></p>
			<?php Admin::form_open( 'migration_backup', Admin::MENU_MIGRATION ); ?>
				<?php submit_button( __( 'Create migration package', 'eikvakt-backup-manager' ), 'secondary', 'submit', false, Job_Runner::has_work() ? [ 'disabled' => 'disabled' ] : [] ); ?>
			</form>

			<?php if ( [] !== $ekbm_packages ) : ?>
				<h3><?php esc_html_e( 'Create a key for a backup', 'eikvakt-backup-manager' ); ?></h3>
				<?php Admin::form_open( 'create_key', Admin::MENU_MIGRATION ); ?>
					<select name="backup_id" class="ekbm-wide">
						<?php foreach ( $ekbm_packages as $ekbm_package ) : ?>
							<option value="<?php echo esc_attr( $ekbm_package->id() ); ?>">
								<?php
								echo esc_html(
									sprintf(
										'%s — %s — %s — %s',
										Util::datetime( $ekbm_package->created() ),
										Admin::type_label( $ekbm_package->type() ),
										implode( ', ', array_map( [ Components::class, 'short_label' ], $ekbm_package->components() ) ),
										Util::bytes( $ekbm_package->total_size() )
									)
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Create migration key', 'eikvakt-backup-manager' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<p class="ekbm-muted">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of active keys */
						_n( '%d migration key is active.', '%d migration keys are active.', count( $ekbm_keys ), 'eikvakt-backup-manager' ),
						count( $ekbm_keys )
					)
				);
				?>
			</p>
			<?php if ( [] !== $ekbm_keys ) : ?>
				<?php Admin::form_open( 'revoke_keys', Admin::MENU_MIGRATION, [ 'class' => 'ekbm-inline' ] ); ?>
					<button type="submit" class="button ekbm-button-delete"><?php esc_html_e( 'Revoke all keys', 'eikvakt-backup-manager' ); ?></button>
				</form>
			<?php endif; ?>
		</div>

		<div class="ekbm-card">
			<h2><span class="ekbm-step">2</span> <?php esc_html_e( 'Import on the target site', 'eikvakt-backup-manager' ); ?></h2>
			<p><?php esc_html_e( 'Do this on the new site. Paste the key from the source site; the backup is copied over directly, server to server.', 'eikvakt-backup-manager' ); ?></p>
			<?php Admin::form_open( 'import_key', Admin::MENU_MIGRATION ); ?>
				<textarea name="migration_key" class="large-text code" rows="3" placeholder="EKBM1-…" required></textarea>
				<p><label><input type="checkbox" name="allow_private" value="1"> <?php esc_html_e( 'The source site is on my private network (internal address, non-standard port or plain http)', 'eikvakt-backup-manager' ); ?></label></p>
				<p class="description"><?php esc_html_e( 'Only import keys you created yourself. Without this option the target only connects to public HTTPS addresses, which keeps a manipulated key from reaching internal services.', 'eikvakt-backup-manager' ); ?></p>
				<?php submit_button( __( 'Import backup', 'eikvakt-backup-manager' ), 'primary', 'submit', false, Job_Runner::has_work() ? [ 'disabled' => 'disabled' ] : [] ); ?>
			</form>
			<h3><?php esc_html_e( 'Other ways to bring a backup over', 'eikvakt-backup-manager' ); ?></h3>
			<ul class="ekbm-checklist">
				<li><?php esc_html_e( 'Shared storage: add the same Dropbox, OneDrive, FTP or SFTP destination on both sites, then use "Rescan all locations" on the Backups screen of the target.', 'eikvakt-backup-manager' ); ?></li>
				<li><?php esc_html_e( 'Upload: download the backup files from the source and upload them on the Backups screen of the target.', 'eikvakt-backup-manager' ); ?></li>
			</ul>
		</div>

		<div class="ekbm-card">
			<h2><span class="ekbm-step">3</span> <?php esc_html_e( 'Restore with the new address', 'eikvakt-backup-manager' ); ?></h2>
			<p><?php esc_html_e( 'On the target, open the imported backup on the Backups screen and choose Restore. The old address and paths are pre-filled for replacement with the new ones; add more pairs if needed. Choose only the parts you want for a selective migration — for example only Uploads, or only the database.', 'eikvakt-backup-manager' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::MENU_BACKUPS ) ); ?>"><?php esc_html_e( 'Go to Backups', 'eikvakt-backup-manager' ); ?></a></p>
		</div>
	</div>
</div>
