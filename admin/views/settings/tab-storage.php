<?php
/**
 * Settings → Storage: where backups are copied to.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ekbm_types        = Destinations::types();
$ekbm_destinations = Destinations::all();

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- selects what to display.
$ekbm_edit_id = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( (string) $_GET['edit'] ) ) : '';
$ekbm_new     = isset( $_GET['add'] ) ? sanitize_key( wp_unslash( (string) $_GET['add'] ) ) : '';
$ekbm_connect = isset( $_GET['connect'] ) ? sanitize_key( wp_unslash( (string) $_GET['connect'] ) ) : '';
// phpcs:enable

$ekbm_has_plugin_app = '' !== Storage_Dropbox::plugin_app_key();

$ekbm_edit = '' !== $ekbm_edit_id ? Destinations::get( $ekbm_edit_id ) : null;
if ( null === $ekbm_edit && isset( $ekbm_types[ $ekbm_new ] ) ) {
	$ekbm_edit = [
		'id'             => '',
		'type'           => $ekbm_new,
		'name'           => $ekbm_types[ $ekbm_new ]['label'],
		'enabled'        => true,
		'retention_days' => 0,
		'config'         => [
			'port'    => 'sftp' === $ekbm_new ? 22 : 21,
			'passive' => true,
			'tenant'  => 'common',
			'own_app' => ! $ekbm_has_plugin_app,
			'path'    => 'ftp' === $ekbm_new || 'sftp' === $ekbm_new ? 'backups/' . wp_parse_url( home_url(), PHP_URL_HOST ) : wp_parse_url( home_url(), PHP_URL_HOST ),
		],
	];
}

$ekbm_status_cell = static function ( array $destination ): string {
	if ( ! Destinations::is_connected( $destination ) ) {
		return Admin::badge( __( 'Not connected', 'eikvakt-backup-manager' ), '#dba617' );
	}
	return ! empty( $destination['enabled'] )
		? Admin::badge( __( 'Active', 'eikvakt-backup-manager' ), '#00a32a' )
		: Admin::badge( __( 'Paused', 'eikvakt-backup-manager' ), '#646970' );
};
?>
<?php
$ekbm_paste_url = '' !== $ekbm_connect ? OAuth::pending_url( $ekbm_connect ) : '';
if ( '' !== $ekbm_paste_url ) :
	$ekbm_paste_dest = Destinations::get( OAuth::pending_destination( $ekbm_connect ) );
	?>
	<div class="ekbm-card ekbm-connect">
		<h2>
			<?php
			/* translators: %s: destination name */
			echo esc_html( sprintf( __( 'Connect "%s" to Dropbox', 'eikvakt-backup-manager' ), null !== $ekbm_paste_dest ? $ekbm_paste_dest['name'] : 'Dropbox' ) );
			?>
		</h2>
		<ol>
			<li>
				<a class="button button-primary" href="<?php echo esc_url( $ekbm_paste_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Dropbox', 'eikvakt-backup-manager' ); ?> ↗</a>
				<?php esc_html_e( 'Sign in if asked and press "Allow". Eikvakt Backup Manager only gets access to its own folder (Apps/Eikvakt Backup Manager), nothing else in your Dropbox.', 'eikvakt-backup-manager' ); ?>
			</li>
			<li><?php esc_html_e( 'Dropbox then shows an access code. Copy it and paste it here:', 'eikvakt-backup-manager' ); ?></li>
		</ol>
		<?php Admin::form_open( 'oauth_paste' ); ?>
			<input type="hidden" name="state" value="<?php echo esc_attr( $ekbm_connect ); ?>">
			<p>
				<input type="text" name="code" class="large-text code" autocomplete="off" required placeholder="<?php esc_attr_e( 'Access code from Dropbox', 'eikvakt-backup-manager' ); ?>">
			</p>
			<?php submit_button( __( 'Finish connecting', 'eikvakt-backup-manager' ), 'primary', 'submit', false ); ?>
		</form>
		<p class="description"><?php esc_html_e( 'The code works once and expires after a few minutes. The connection is stored only on this site; it can be removed at any time under Dropbox → Settings → Apps → Connected apps.', 'eikvakt-backup-manager' ); ?></p>
	</div>
<?php endif; ?>

<?php if ( Util::is_playground() ) : ?>
	<div class="notice notice-warning inline"><p><?php echo esc_html( Util::playground_notice() ); ?></p></div>
<?php endif; ?>

<p class="description"><?php esc_html_e( 'Every backup is written to this server first and then copied to all active destinations at the same time. If one destination is unavailable, the others still receive their copy.', 'eikvakt-backup-manager' ); ?></p>

<table class="widefat striped ekbm-destinations">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Destination', 'eikvakt-backup-manager' ); ?></th>
			<th><?php esc_html_e( 'Type', 'eikvakt-backup-manager' ); ?></th>
			<th><?php esc_html_e( 'Status', 'eikvakt-backup-manager' ); ?></th>
			<th><?php esc_html_e( 'Keep for', 'eikvakt-backup-manager' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'eikvakt-backup-manager' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td><strong><?php esc_html_e( 'This server', 'eikvakt-backup-manager' ); ?></strong><br><code class="ekbm-muted"><?php echo esc_html( Paths::backup_dir() ); ?></code></td>
			<td><?php esc_html_e( 'Local', 'eikvakt-backup-manager' ); ?></td>
			<td><?php echo Admin::badge( __( 'Active', 'eikvakt-backup-manager' ), '#00a32a' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></td>
			<td><?php $ekbm_ld = (int) Settings::get( Installer::OPTION_RETENTION )['local_days'];
echo esc_html( $ekbm_ld > 0 ? sprintf( /* translators: %d: days */ _n( '%d day', '%d days', $ekbm_ld, 'eikvakt-backup-manager' ), $ekbm_ld ) : __( 'Retention rules', 'eikvakt-backup-manager' ) ); ?></td>
			<td class="ekbm-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::MENU_SETTINGS . '&tab=general' ) ); ?>"><?php esc_html_e( 'Settings', 'eikvakt-backup-manager' ); ?></a></td>
		</tr>
		<?php foreach ( $ekbm_destinations as $ekbm_id => $ekbm_destination ) : ?>
			<tr>
				<td><strong><?php echo esc_html( (string) $ekbm_destination['name'] ); ?></strong></td>
				<td><?php echo esc_html( $ekbm_types[ $ekbm_destination['type'] ]['label'] ); ?></td>
				<td><?php echo $ekbm_status_cell( $ekbm_destination ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></td>
				<td>
					<?php
					$ekbm_days = (int) ( $ekbm_destination['retention_days'] ?? 0 );
					$ekbm_rd   = (int) Settings::get( Installer::OPTION_RETENTION )['remote_days'];
					$ekbm_eff  = $ekbm_days > 0 ? $ekbm_days : $ekbm_rd;
					/* translators: %d: days */
					echo esc_html( $ekbm_eff > 0 ? sprintf( _n( '%d day', '%d days', $ekbm_eff, 'eikvakt-backup-manager' ), $ekbm_eff ) : __( 'Retention rules', 'eikvakt-backup-manager' ) );
					?>
				</td>
				<td class="ekbm-actions">
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::MENU_SETTINGS . '&tab=storage&edit=' . $ekbm_id ) ); ?>"><?php esc_html_e( 'Edit', 'eikvakt-backup-manager' ); ?></a>
					<?php if ( ! empty( $ekbm_types[ $ekbm_destination['type'] ]['oauth'] ) ) : ?>
						<?php Admin::form_open( 'oauth_connect', Admin::MENU_SETTINGS, [ 'class' => 'ekbm-inline' ] ); ?>
							<input type="hidden" name="destination" value="<?php echo esc_attr( (string) $ekbm_id ); ?>">
							<button type="submit" class="button"><?php echo esc_html( Destinations::is_connected( $ekbm_destination ) ? __( 'Reconnect', 'eikvakt-backup-manager' ) : __( 'Connect', 'eikvakt-backup-manager' ) ); ?></button>
						</form>
					<?php endif; ?>
					<?php Admin::form_open( 'test_destination', Admin::MENU_SETTINGS, [ 'class' => 'ekbm-inline' ] ); ?>
						<input type="hidden" name="destination" value="<?php echo esc_attr( (string) $ekbm_id ); ?>">
						<button type="submit" class="button"><?php esc_html_e( 'Test', 'eikvakt-backup-manager' ); ?></button>
					</form>
					<?php Admin::form_open( 'delete_destination', Admin::MENU_SETTINGS, [ 'class' => 'ekbm-inline ekbm-confirm-remove' ] ); ?>
						<input type="hidden" name="destination" value="<?php echo esc_attr( (string) $ekbm_id ); ?>">
						<button type="submit" class="button ekbm-button-delete"><?php esc_html_e( 'Remove', 'eikvakt-backup-manager' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<p class="ekbm-add">
	<?php esc_html_e( 'Add a destination:', 'eikvakt-backup-manager' ); ?>
	<?php foreach ( $ekbm_types as $ekbm_type => $ekbm_def ) : ?>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::MENU_SETTINGS . '&tab=storage&add=' . $ekbm_type ) ); ?>"><?php echo esc_html( $ekbm_def['label'] ); ?></a>
	<?php endforeach; ?>
</p>

<?php if ( null !== $ekbm_edit ) : ?>
	<?php
	$ekbm_def     = $ekbm_types[ $ekbm_edit['type'] ];
	$ekbm_config  = (array) $ekbm_edit['config'];
	$ekbm_own_app = 'dropbox' !== $ekbm_edit['type'] || ! $ekbm_has_plugin_app || ! empty( $ekbm_config['own_app'] );
	?>
	<div class="ekbm-card">
		<h2>
			<?php
			echo esc_html(
				'' === $ekbm_edit['id']
					/* translators: %s: destination type */
					? sprintf( __( 'New %s destination', 'eikvakt-backup-manager' ), $ekbm_def['label'] )
					/* translators: %s: destination name */
					: sprintf( __( 'Edit "%s"', 'eikvakt-backup-manager' ), $ekbm_edit['name'] )
			);
			?>
		</h2>

		<?php if ( 'dropbox' === $ekbm_edit['type'] ) : ?>
			<?php if ( $ekbm_has_plugin_app ) : ?>
				<div class="ekbm-guide ekbm-plugin-only" <?php echo $ekbm_own_app ? 'hidden' : ''; ?>>
					<h3><?php esc_html_e( 'Connect in three steps', 'eikvakt-backup-manager' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Save this destination.', 'eikvakt-backup-manager' ); ?></li>
						<li><?php esc_html_e( 'Press "Connect" in the list above and allow access in Dropbox.', 'eikvakt-backup-manager' ); ?></li>
						<li><?php esc_html_e( 'Paste the code Dropbox shows. Done.', 'eikvakt-backup-manager' ); ?></li>
					</ol>
					<p class="description"><?php esc_html_e( 'No Dropbox app of your own is needed: Eikvakt Backup Manager appears under "Connected apps" in your Dropbox and can only access its own folder Apps/Eikvakt Backup Manager.', 'eikvakt-backup-manager' ); ?></p>
				</div>
			<?php else : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'This copy of the plugin has no built-in Dropbox app, so connect it with your own app as described below.', 'eikvakt-backup-manager' ); ?></p></div>
			<?php endif; ?>
			<div class="ekbm-guide ekbm-own-only" <?php echo $ekbm_own_app ? '' : 'hidden'; ?>>
				<h3><?php esc_html_e( 'Create your Dropbox app (once, about 3 minutes)', 'eikvakt-backup-manager' ); ?></h3>
				<ol>
					<li><?php echo wp_kses(
						__( 'Open <a href="https://www.dropbox.com/developers/apps/create" target="_blank" rel="noopener noreferrer">dropbox.com/developers/apps/create</a> and sign in.', 'eikvakt-backup-manager' ),
						[
							'a' => [
								'href'   => [],
								'target' => [],
								'rel'    => [],
							],
						]
					); ?></li>
					<li><?php esc_html_e( 'Choose "Scoped access" and "App folder", give the app a name, and create it.', 'eikvakt-backup-manager' ); ?></li>
					<li><?php esc_html_e( 'On the Permissions tab, tick files.metadata.read, files.metadata.write, files.content.read and files.content.write, then "Submit".', 'eikvakt-backup-manager' ); ?></li>
					<li><?php esc_html_e( 'On the Settings tab, add this Redirect URI:', 'eikvakt-backup-manager' ); ?> <code class="ekbm-copyable"><?php echo esc_html( OAuth::redirect_uri() ); ?></code></li>
					<li><?php esc_html_e( 'Copy the App key (and App secret) into the fields below, save, then press "Connect".', 'eikvakt-backup-manager' ); ?></li>
				</ol>
				<p class="description"><?php esc_html_e( 'Dropbox accepts plain http only for localhost; a live site needs https.', 'eikvakt-backup-manager' ); ?></p>
			</div>
		<?php elseif ( 'onedrive' === $ekbm_edit['type'] ) : ?>
			<div class="ekbm-guide">
				<h3><?php esc_html_e( 'Register your Microsoft app (once, about 5 minutes)', 'eikvakt-backup-manager' ); ?></h3>
				<ol>
					<li><?php echo wp_kses(
						__( 'Open <a href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener noreferrer">Microsoft Entra → App registrations</a> and choose "New registration".', 'eikvakt-backup-manager' ),
						[
							'a' => [
								'href'   => [],
								'target' => [],
								'rel'    => [],
							],
						]
					); ?></li>
					<li><?php esc_html_e( 'Supported account types: "Accounts in any organizational directory and personal Microsoft accounts".', 'eikvakt-backup-manager' ); ?></li>
					<li><?php esc_html_e( 'Redirect URI: platform "Web", value:', 'eikvakt-backup-manager' ); ?> <code class="ekbm-copyable"><?php echo esc_html( OAuth::redirect_uri() ); ?></code></li>
					<li><?php esc_html_e( 'Under "Certificates & secrets", create a client secret and copy its value.', 'eikvakt-backup-manager' ); ?></li>
					<li><?php esc_html_e( 'Under "API permissions", add Microsoft Graph → Delegated → Files.ReadWrite.AppFolder and offline_access.', 'eikvakt-backup-manager' ); ?></li>
					<li><?php esc_html_e( 'Copy the Application (client) ID and the secret into the fields below, save, then press "Connect".', 'eikvakt-backup-manager' ); ?></li>
				</ol>
				<p class="description"><?php esc_html_e( 'Tenant: "common" for most cases, "consumers" for personal accounts only, or your organisation\'s tenant ID.', 'eikvakt-backup-manager' ); ?></p>
			</div>
		<?php elseif ( 'ftp' === $ekbm_edit['type'] ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Without FTPS, the password and the backups travel unencrypted. Use FTPS or SFTP whenever the server supports it, and turn on backup encryption.', 'eikvakt-backup-manager' ); ?></p></div>
		<?php endif; ?>

		<?php Admin::form_open( 'save_destination', Admin::MENU_SETTINGS, [ 'class' => 'ekbm-dest-form' ] ); ?>
			<input type="hidden" name="destination" value="<?php echo esc_attr( (string) $ekbm_edit['id'] ); ?>">
			<input type="hidden" name="type" value="<?php echo esc_attr( (string) $ekbm_edit['type'] ); ?>">
			<table class="form-table ekbm-dest-table" role="presentation" data-own-app="<?php echo $ekbm_own_app ? '1' : '0'; ?>">
				<tr>
					<th scope="row"><label for="ekbm-dest-name"><?php esc_html_e( 'Name', 'eikvakt-backup-manager' ); ?></label></th>
					<td><input type="text" id="ekbm-dest-name" name="name" class="regular-text" value="<?php echo esc_attr( (string) $ekbm_edit['name'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Active', 'eikvakt-backup-manager' ); ?></th>
					<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $ekbm_edit['enabled'] ) ); ?>> <?php esc_html_e( 'Copy new backups to this destination', 'eikvakt-backup-manager' ); ?></label></td>
				</tr>
				<?php foreach ( $ekbm_def['fields'] as $ekbm_field => $ekbm_kind ) : ?>
					<?php
					$ekbm_secret = in_array( $ekbm_field, $ekbm_def['secrets'], true );
					$ekbm_value  = $ekbm_secret ? '' : ( $ekbm_config[ $ekbm_field ] ?? '' );
					$ekbm_stored = $ekbm_secret && '' !== (string) ( $ekbm_config[ $ekbm_field ] ?? '' );
					$ekbm_name   = 'config[' . $ekbm_field . ']';
					$ekbm_fid    = 'ekbm-f-' . $ekbm_field;
					$ekbm_row    = 'dropbox' === $ekbm_edit['type'] && in_array( $ekbm_field, [ 'app_key', 'app_secret' ], true ) ? 'ekbm-own-only' : '';

					// Without a built-in app the own-app mode is the only one.
					if ( 'own_app' === $ekbm_field && ! $ekbm_has_plugin_app ) {
						echo '<input type="hidden" name="config[own_app]" value="1">';
						continue;
					}
					?>
					<tr class="<?php echo esc_attr( $ekbm_row ); ?>">
						<th scope="row"><label for="<?php echo esc_attr( $ekbm_fid ); ?>"><?php echo esc_html( Destinations::field_label( $ekbm_field ) ); ?></label></th>
						<td>
							<?php if ( 'checkbox' === $ekbm_kind ) : ?>
								<input type="checkbox" id="<?php echo esc_attr( $ekbm_fid ); ?>" name="<?php echo esc_attr( $ekbm_name ); ?>" value="1" <?php checked( ! empty( $ekbm_config[ $ekbm_field ] ) ); ?> <?php echo 'own_app' === $ekbm_field ? 'class="ekbm-own-app-toggle"' : ''; ?>>
								<?php if ( 'own_app' === $ekbm_field ) : ?>
									<label for="<?php echo esc_attr( $ekbm_fid ); ?>"><?php esc_html_e( 'Advanced: use my own Dropbox app instead of the built-in one', 'eikvakt-backup-manager' ); ?></label>
								<?php endif; ?>
							<?php elseif ( 'textarea' === $ekbm_kind ) : ?>
								<textarea id="<?php echo esc_attr( $ekbm_fid ); ?>" name="<?php echo esc_attr( $ekbm_name ); ?>" rows="4" class="large-text code" autocomplete="off" placeholder="<?php echo $ekbm_stored ? esc_attr__( 'Stored — leave empty to keep', 'eikvakt-backup-manager' ) : '-----BEGIN OPENSSH PRIVATE KEY-----'; ?>"></textarea>
							<?php else : ?>
								<input type="<?php echo esc_attr( 'password' === $ekbm_kind ? 'password' : ( 'number' === $ekbm_kind ? 'number' : 'text' ) ); ?>" id="<?php echo esc_attr( $ekbm_fid ); ?>" name="<?php echo esc_attr( $ekbm_name ); ?>" class="<?php echo 'number' === $ekbm_kind ? 'small-text' : 'regular-text'; ?>" value="<?php echo esc_attr( (string) $ekbm_value ); ?>" autocomplete="off" <?php echo $ekbm_stored ? 'placeholder="' . esc_attr__( 'Stored — leave empty to keep', 'eikvakt-backup-manager' ) . '"' : ''; ?>>
							<?php endif; ?>
							<?php if ( $ekbm_stored ) : ?>
								<label class="ekbm-clear"><input type="checkbox" name="config[<?php echo esc_attr( $ekbm_field ); ?>_clear]" value="1"> <?php esc_html_e( 'Remove', 'eikvakt-backup-manager' ); ?></label>
							<?php endif; ?>
							<?php if ( 'path' === $ekbm_field ) : ?>
								<p class="description"><?php echo esc_html( in_array( $ekbm_edit['type'], [ 'dropbox', 'onedrive' ], true ) ? __( 'Folder inside the app folder. One folder per site keeps backups of several sites apart.', 'eikvakt-backup-manager' ) : __( 'Created if it does not exist. Relative paths start in the login directory.', 'eikvakt-backup-manager' ) ); ?></p>
							<?php elseif ( 'tls_pin' === $ekbm_field ) : ?>
								<p class="description"><?php esc_html_e( 'Leave empty: the certificate must be valid for the host name. Only for your own server with a self-signed certificate: press "Test", compare the key shown with the one on your server, and enter it here (sha256//…).', 'eikvakt-backup-manager' ); ?></p>
							<?php elseif ( 'fingerprint' === $ekbm_field ) : ?>
								<p class="description"><?php esc_html_e( 'Filled in automatically on the first successful test, then checked on every connection. Clear it only if the server\'s key changed on purpose.', 'eikvakt-backup-manager' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="ekbm-dest-days"><?php esc_html_e( 'Keep backups here for', 'eikvakt-backup-manager' ); ?></label></th>
					<td>
						<input type="number" id="ekbm-dest-days" name="retention_days" min="0" max="3650" class="small-text" value="<?php echo esc_attr( (string) (int) ( $ekbm_edit['retention_days'] ?? 0 ) ); ?>"> <?php esc_html_e( 'days', 'eikvakt-backup-manager' ); ?>
						<p class="description"><?php esc_html_e( '0 = use the remote setting on the Retention tab.', 'eikvakt-backup-manager' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save destination', 'eikvakt-backup-manager' ) ); ?>
		</form>
	</div>
<?php endif; ?>
