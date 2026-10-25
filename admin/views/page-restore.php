<?php
/**
 * Restore one backup: choose parts, tables, search/replace, options.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects what to display; the restore itself is a nonce-checked POST.
$ekbm_id       = isset( $_GET['backup'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['backup'] ) ) : '';
$ekbm_manifest = Catalog::get( $ekbm_id );
?>
<div class="wrap ekbm-wrap">
	<?php Admin::header( __( 'Restore', 'eikvakt-backup-manager' ) ); ?>

	<?php if ( null === $ekbm_manifest ) : ?>
		<p><?php esc_html_e( 'Choose a backup on the Backups screen.', 'eikvakt-backup-manager' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::MENU_BACKUPS ) ); ?>"><?php esc_html_e( 'Backups', 'eikvakt-backup-manager' ); ?></a></p>
	</div>
		<?php
		return;
	endif;

	$ekbm_chain     = Catalog::chain_for( $ekbm_id );
	$ekbm_locations = Catalog::locations( $ekbm_id );
	$ekbm_migrating = untrailingslashit( $ekbm_manifest->site( 'home' ) ) !== untrailingslashit( home_url() );

	// Pre-filled replacements when the backup comes from another address or path.
	$ekbm_pairs = [];
	$ekbm_add   = static function ( string $from, string $to ) use ( &$ekbm_pairs ): void {
		$from = untrailingslashit( $from );
		$to   = untrailingslashit( $to );
		if ( '' !== $from && $from !== $to && ! in_array( [ $from, $to ], $ekbm_pairs, true ) ) {
			$ekbm_pairs[] = [ $from, $to ];
		}
	};
	$ekbm_add( $ekbm_manifest->site( 'home' ), home_url() );
	$ekbm_add( $ekbm_manifest->site( 'siteurl' ), site_url() );
	$ekbm_add( $ekbm_manifest->site( 'abspath' ), wp_normalize_path( ABSPATH ) );
	$ekbm_add( $ekbm_manifest->site( 'content_dir' ), wp_normalize_path( WP_CONTENT_DIR ) );
	?>

	<div class="ekbm-card">
		<table class="widefat striped ekbm-facts">
			<tbody>
				<tr><th><?php esc_html_e( 'Backup', 'eikvakt-backup-manager' ); ?></th><td><code><?php echo esc_html( $ekbm_id ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Created', 'eikvakt-backup-manager' ); ?></th><td><?php echo esc_html( Util::datetime( $ekbm_manifest->created() ) ); ?> — <?php echo Admin::badge( Admin::type_label( $ekbm_manifest->type() ), Admin::type_color( $ekbm_manifest->type() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></td></tr>
				<tr><th><?php esc_html_e( 'Source site', 'eikvakt-backup-manager' ); ?></th><td><?php echo esc_html( $ekbm_manifest->site( 'home' ) ); ?> (WordPress <?php echo esc_html( $ekbm_manifest->site( 'wp_version' ) ); ?>, <?php esc_html_e( 'table prefix', 'eikvakt-backup-manager' ); ?> <code><?php echo esc_html( $ekbm_manifest->site( 'table_prefix' ) ); ?></code>)</td></tr>
				<tr><th><?php esc_html_e( 'Size', 'eikvakt-backup-manager' ); ?></th><td><?php echo esc_html( Util::bytes( $ekbm_manifest->total_size() ) ); ?></td></tr>
				<?php if ( Manifest::TYPE_INCREMENTAL === $ekbm_manifest->type() ) : ?>
					<tr><th><?php esc_html_e( 'Chain', 'eikvakt-backup-manager' ); ?></th><td>
						<?php
						echo [] === $ekbm_chain
							? '<strong style="color:#d63638;">' . esc_html__( 'Incomplete: an earlier backup of this chain is missing. Files cannot be restored; the database can.', 'eikvakt-backup-manager' ) . '</strong>'
							/* translators: %d: number of backups */
							: esc_html( sprintf( __( 'Uses %d backups (one full backup and the incrementals up to this one).', 'eikvakt-backup-manager' ), count( $ekbm_chain ) ) );
						?>
					</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<?php if ( $ekbm_migrating ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'This backup comes from a different address. The addresses and paths below are replaced during the restore so the site works at its new location.', 'eikvakt-backup-manager' ); ?></p></div>
	<?php endif; ?>

	<?php Admin::form_open( 'start_restore', Admin::MENU_RESTORE, [ 'class' => 'ekbm-restore-form' ] ); ?>
		<input type="hidden" name="backup_id" value="<?php echo esc_attr( $ekbm_id ); ?>">

		<h2><?php esc_html_e( 'What to restore', 'eikvakt-backup-manager' ); ?></h2>
		<fieldset class="ekbm-components">
			<?php foreach ( $ekbm_manifest->components() as $ekbm_component ) : ?>
				<label><input type="checkbox" name="components[]" value="<?php echo esc_attr( $ekbm_component ); ?>" checked> <?php echo esc_html( Components::label( $ekbm_component ) ); ?></label>
			<?php endforeach; ?>
		</fieldset>

		<?php if ( [] !== $ekbm_manifest->tables() ) : ?>
			<details class="ekbm-tables">
				<summary><?php esc_html_e( 'Choose database tables', 'eikvakt-backup-manager' ); ?> (<?php echo esc_html( number_format_i18n( count( $ekbm_manifest->tables() ) ) ); ?>)</summary>
				<p><button type="button" class="button-link ekbm-toggle-all" data-target=".ekbm-table-list"><?php esc_html_e( 'Select all / none', 'eikvakt-backup-manager' ); ?></button></p>
				<div class="ekbm-table-list">
					<?php foreach ( $ekbm_manifest->tables() as $ekbm_table_name ) : ?>
						<label><input type="checkbox" name="tables[]" value="<?php echo esc_attr( $ekbm_table_name ); ?>" checked> <code><?php echo esc_html( $ekbm_table_name ); ?></code></label>
					<?php endforeach; ?>
				</div>
			</details>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Search and replace', 'eikvakt-backup-manager' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Applied to the restored database, including serialised and JSON data. Use it to move the site to a new domain, a new path, or from http to https.', 'eikvakt-backup-manager' ); ?></p>
		<table class="widefat ekbm-replace" id="ekbm-replace">
			<thead><tr><th><?php esc_html_e( 'Search for', 'eikvakt-backup-manager' ); ?></th><th><?php esc_html_e( 'Replace with', 'eikvakt-backup-manager' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php foreach ( array_merge( $ekbm_pairs, [ [ '', '' ] ] ) as $ekbm_pair ) : ?>
					<tr>
						<td><input type="text" name="replace_from[]" class="large-text code" value="<?php echo esc_attr( $ekbm_pair[0] ); ?>"></td>
						<td><input type="text" name="replace_to[]" class="large-text code" value="<?php echo esc_attr( $ekbm_pair[1] ); ?>"></td>
						<td><button type="button" class="button-link ekbm-remove-row" aria-label="<?php esc_attr_e( 'Remove', 'eikvakt-backup-manager' ); ?>">×</button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button" id="ekbm-add-row"><?php esc_html_e( 'Add a replacement', 'eikvakt-backup-manager' ); ?></button></p>

		<h2><?php esc_html_e( 'Options', 'eikvakt-backup-manager' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php if ( $ekbm_manifest->is_encrypted() ) : ?>
				<tr>
					<th scope="row"><label for="ekbm-passphrase"><?php esc_html_e( 'Passphrase', 'eikvakt-backup-manager' ); ?></label></th>
					<td>
						<input type="password" id="ekbm-passphrase" name="passphrase" class="regular-text" autocomplete="off">
						<p class="description"><?php esc_html_e( 'This backup is encrypted. Leave empty to use the passphrase stored on this site.', 'eikvakt-backup-manager' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
			<?php if ( count( $ekbm_locations ) > 1 || ! in_array( Storage_Local::ID, $ekbm_locations, true ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Restore from', 'eikvakt-backup-manager' ); ?></th>
					<td>
						<?php foreach ( array_values( array_diff( $ekbm_locations, [ Storage_Local::ID ] ) ) as $ekbm_i => $ekbm_location ) : ?>
							<label><input type="radio" name="source" value="<?php echo esc_attr( $ekbm_location ); ?>" <?php checked( 0, $ekbm_i ); ?>> <?php echo esc_html( Destinations::name( $ekbm_location ) ); ?></label><br>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Files already on this server are used directly; the rest is downloaded from here.', 'eikvakt-backup-manager' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Safety', 'eikvakt-backup-manager' ); ?></th>
				<td>
					<label><input type="checkbox" name="safety" value="1" <?php checked( ! $ekbm_migrating ); ?>> <?php esc_html_e( 'Back up the current state of the selected parts first', 'eikvakt-backup-manager' ); ?></label><br>
					<label><input type="checkbox" name="delete_extra" value="1"> <?php esc_html_e( 'Delete files that were not part of the backup (exact rollback of the selected parts)', 'eikvakt-backup-manager' ); ?></label><br>
					<label><input type="checkbox" name="wp_config" value="1"> <?php esc_html_e( 'Also restore wp-config.php', 'eikvakt-backup-manager' ); ?></label>
					<p class="description"><?php esc_html_e( 'wp-config.php holds this server\'s database credentials and is left alone unless ticked. This plugin\'s own files and settings are never overwritten by a restore.', 'eikvakt-backup-manager' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Restore now', 'eikvakt-backup-manager' ), 'primary', 'submit', true, Job_Runner::has_work() ? [ 'disabled' => 'disabled' ] : [] ); ?>
	</form>
</div>
