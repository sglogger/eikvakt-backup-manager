<?php
/**
 * Settings → Database: which tables are backed up.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$ekbm_db     = Settings::get( Installer::OPTION_DATABASE );
$ekbm_tables = Database_Dumper::site_tables();

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table sizes for display.
$ekbm_status = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS WHERE `Name` LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A );
$ekbm_sizes  = [];
foreach ( $ekbm_status as $ekbm_row ) {
	$ekbm_sizes[ (string) $ekbm_row['Name'] ] = [ (int) $ekbm_row['Data_length'] + (int) $ekbm_row['Index_length'], (int) $ekbm_row['Rows'] ];
}
?>
<?php Admin::form_open( 'save_database' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Tables', 'eikvakt-backup-manager' ); ?></th>
			<td>
				<label><input type="radio" name="mode" value="all" <?php checked( 'all', $ekbm_db['mode'] ); ?>> <?php esc_html_e( 'All tables of this site (recommended)', 'eikvakt-backup-manager' ); ?></label><br>
				<label><input type="radio" name="mode" value="selected" <?php checked( 'selected', $ekbm_db['mode'] ); ?>> <?php esc_html_e( 'Only the tables selected below', 'eikvakt-backup-manager' ); ?></label>
				<p class="description">
					<?php
					/* translators: %s: table prefix */
					echo esc_html( sprintf( __( 'Tables with the prefix %s. New tables created by plugins later are included automatically with "All tables".', 'eikvakt-backup-manager' ), $wpdb->prefix ) );
					?>
				</p>
			</td>
		</tr>
	</table>

	<p><button type="button" class="button-link ekbm-toggle-all" data-target=".ekbm-table-list"><?php esc_html_e( 'Select all / none', 'eikvakt-backup-manager' ); ?></button></p>
	<div class="ekbm-table-list ekbm-table-list-wide">
		<?php foreach ( $ekbm_tables as $ekbm_table ) : ?>
			<?php $ekbm_info = $ekbm_sizes[ $ekbm_table ] ?? [ 0, 0 ]; ?>
			<label>
				<input type="checkbox" name="tables[]" value="<?php echo esc_attr( $ekbm_table ); ?>" <?php checked( 'all' === $ekbm_db['mode'] || in_array( $ekbm_table, (array) $ekbm_db['tables'], true ) ); ?>>
				<code><?php echo esc_html( $ekbm_table ); ?></code>
				<span class="ekbm-muted"><?php echo esc_html( Util::bytes( $ekbm_info[0] ) . ' · ' . number_format_i18n( $ekbm_info[1] ) ); ?></span>
			</label>
		<?php endforeach; ?>
	</div>
	<?php submit_button(); ?>
</form>
