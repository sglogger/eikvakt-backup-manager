<?php
/**
 * Backups: list, restore, download, delete, rescan, upload.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ekbm_table = new Backups_List_Table();
$ekbm_table->prepare_items();
?>
<div class="wrap ekbm-wrap">
	<?php Admin::header( __( 'Backups', 'eikvakt-backup-manager' ) ); ?>

	<div class="ekbm-toolbar">
		<?php Admin::form_open( 'rescan', Admin::MENU_BACKUPS, [ 'class' => 'ekbm-inline' ] ); ?>
			<button type="submit" class="button"><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Rescan all locations', 'eikvakt-backup-manager' ); ?></button>
		</form>
		<details class="ekbm-upload">
			<summary class="button"><span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Upload a backup', 'eikvakt-backup-manager' ); ?></summary>
			<div class="ekbm-card">
				<p><?php esc_html_e( 'Select all files of one backup set (the .manifest.json and every file starting with the same name). They are uploaded in chunks, so size limits of the server do not apply.', 'eikvakt-backup-manager' ); ?></p>
				<input type="file" id="ekbm-upload-files" multiple>
				<p id="ekbm-upload-status" class="ekbm-muted" aria-live="polite"></p>
			</div>
		</details>
	</div>

	<p class="description">
		<?php esc_html_e( 'Incremental backups only contain files that changed; restoring one automatically uses the full backup and every incremental before it. Downloads are only possible while signed in here — backup files are never reachable by URL.', 'eikvakt-backup-manager' ); ?>
	</p>

	<?php $ekbm_table->views(); ?>
	<?php $ekbm_table->display(); ?>
</div>
