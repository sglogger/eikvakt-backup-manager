<?php
/**
 * Settings → Files: which folders are backed up.
 *
 * The tree is loaded folder by folder over AJAX (ekbm_tree) and edits a
 * set of folder rules kept in a hidden field as JSON; File_Rules applies
 * them during the backup.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ekbm_settings = Settings::general();
$ekbm_rules    = File_Rules::sanitize( (array) ( $ekbm_settings['file_rules'] ?? [] ) );
?>
<?php Admin::form_open( 'save_files', Admin::MENU_SETTINGS, [ 'class' => 'ekbm-files-form' ] ); ?>
	<p class="description">
		<?php esc_html_e( 'Untick a folder to leave it out of file backups; tick it to include it. A rule applies to everything inside the folder, and the most specific rule wins: you can exclude "uploads" and still include one folder inside it, or include a folder that is skipped by default. Which parts are backed up at all (core, plugins, themes, uploads, other files) is chosen per backup and on the Schedule tab.', 'eikvakt-backup-manager' ); ?>
	</p>

	<div class="ekbm-tree-legend">
		<span><input type="checkbox" checked disabled> <?php esc_html_e( 'included', 'eikvakt-backup-manager' ); ?></span>
		<span><input type="checkbox" disabled> <?php esc_html_e( 'excluded', 'eikvakt-backup-manager' ); ?></span>
		<span><?php echo Admin::badge( __( 'rule', 'eikvakt-backup-manager' ), '#2271b1' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?> <?php esc_html_e( 'your own setting for this folder', 'eikvakt-backup-manager' ); ?></span>
		<span><?php echo Admin::badge( __( 'default', 'eikvakt-backup-manager' ), '#646970' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?> <?php esc_html_e( 'skipped by default (cache, other backups)', 'eikvakt-backup-manager' ); ?></span>
	</div>

	<div id="ekbm-tree" class="ekbm-tree" aria-live="polite"
		data-rules="<?php echo esc_attr( (string) wp_json_encode( (object) $ekbm_rules ) ); ?>">
		<p class="ekbm-muted"><?php esc_html_e( 'Loading folders…', 'eikvakt-backup-manager' ); ?></p>
	</div>
	<input type="hidden" name="file_rules" id="ekbm-file-rules" value="<?php echo esc_attr( (string) wp_json_encode( (object) $ekbm_rules ) ); ?>">

	<h2><?php esc_html_e( 'Your folder rules', 'eikvakt-backup-manager' ); ?></h2>
	<div id="ekbm-rule-list" class="ekbm-rule-list"></div>

	<h2><?php esc_html_e( 'Advanced', 'eikvakt-backup-manager' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="ekbm-excludes"><?php esc_html_e( 'Exclude by pattern', 'eikvakt-backup-manager' ); ?></label></th>
			<td>
				<textarea id="ekbm-excludes" name="excludes" rows="4" class="large-text code"><?php echo esc_textarea( (string) $ekbm_settings['excludes'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One pattern per line, for files or folders anywhere. Examples: *.log  ·  *.tmp  ·  content/uploads/*/cache/*. Paths start with root/ (the WordPress directory) or content/ (wp-content). Folder rules from the tree take precedence; file-name patterns such as *.log apply everywhere.', 'eikvakt-backup-manager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="ekbm-maxsize"><?php esc_html_e( 'Skip files larger than', 'eikvakt-backup-manager' ); ?></label></th>
			<td><input type="number" id="ekbm-maxsize" name="exclude_larger_than_mb" min="0" class="small-text" value="<?php echo esc_attr( (string) $ekbm_settings['exclude_larger_than_mb'] ); ?>"> MB <span class="description"><?php esc_html_e( '0 = no limit', 'eikvakt-backup-manager' ); ?></span></td>
		</tr>
	</table>

	<?php submit_button(); ?>
</form>
