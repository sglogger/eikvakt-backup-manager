<?php
/**
 * Settings → Schedule.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wp_locale;

$ekbm_schedule = Settings::get( Installer::OPTION_SCHEDULE );

$ekbm_frequencies = [
	'off'      => __( 'Off', 'eikvakt-backup-manager' ),
	'interval' => __( 'Every few hours', 'eikvakt-backup-manager' ),
	'daily'    => __( 'Daily', 'eikvakt-backup-manager' ),
	'weekly'   => __( 'Weekly', 'eikvakt-backup-manager' ),
	'monthly'  => __( 'Monthly', 'eikvakt-backup-manager' ),
];

/**
 * Frequency/time controls for one schedule.
 *
 * @param string               $kind   files|database.
 * @param array<string, mixed> $config Current config.
 */
$ekbm_controls = static function ( string $kind, array $config ) use ( $ekbm_frequencies, $wp_locale ): void {
	?>
	<div class="ekbm-schedule" data-frequency="<?php echo esc_attr( (string) $config['frequency'] ); ?>">
		<select name="<?php echo esc_attr( $kind ); ?>[frequency]" class="ekbm-frequency">
			<?php foreach ( $ekbm_frequencies as $ekbm_value => $ekbm_label ) : ?>
				<option value="<?php echo esc_attr( $ekbm_value ); ?>" <?php selected( $ekbm_value, $config['frequency'] ); ?>><?php echo esc_html( $ekbm_label ); ?></option>
			<?php endforeach; ?>
		</select>
		<span class="ekbm-when ekbm-when-interval">
			<?php esc_html_e( 'every', 'eikvakt-backup-manager' ); ?>
			<input type="number" min="1" max="168" class="small-text" name="<?php echo esc_attr( $kind ); ?>[interval_hours]" value="<?php echo esc_attr( (string) $config['interval_hours'] ); ?>">
			<?php esc_html_e( 'hours', 'eikvakt-backup-manager' ); ?>
		</span>
		<span class="ekbm-when ekbm-when-weekly">
			<?php esc_html_e( 'on', 'eikvakt-backup-manager' ); ?>
			<select name="<?php echo esc_attr( $kind ); ?>[weekday]">
				<?php for ( $ekbm_d = 1; $ekbm_d <= 7; $ekbm_d++ ) : ?>
					<option value="<?php echo esc_attr( (string) $ekbm_d ); ?>" <?php selected( $ekbm_d, (int) $config['weekday'] ); ?>><?php echo esc_html( $wp_locale->get_weekday( $ekbm_d % 7 ) ); ?></option>
				<?php endfor; ?>
			</select>
		</span>
		<span class="ekbm-when ekbm-when-monthly">
			<?php esc_html_e( 'on day', 'eikvakt-backup-manager' ); ?>
			<input type="number" min="1" max="31" class="small-text" name="<?php echo esc_attr( $kind ); ?>[monthday]" value="<?php echo esc_attr( (string) $config['monthday'] ); ?>">
		</span>
		<span class="ekbm-when ekbm-when-daily ekbm-when-weekly ekbm-when-monthly">
			<?php esc_html_e( 'at', 'eikvakt-backup-manager' ); ?>
			<input type="time" name="<?php echo esc_attr( $kind ); ?>[time]" value="<?php echo esc_attr( (string) $config['time'] ); ?>">
		</span>
	</div>
	<?php
};
?>
<?php Admin::form_open( 'save_schedule' ); ?>
	<p class="description">
		<?php
		/* translators: %s: timezone name */
		echo esc_html( sprintf( __( 'Times are in the site\'s timezone (%s). WordPress runs scheduled tasks when the site receives visits; on a very quiet site, set up a real cron job for wp-cron.php for exact timing.', 'eikvakt-backup-manager' ), wp_timezone_string() ) );
		?>
	</p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'File backups', 'eikvakt-backup-manager' ); ?></th>
			<td>
				<?php $ekbm_controls( 'files', (array) $ekbm_schedule['files'] ); ?>
				<p>
					<?php esc_html_e( 'A full backup every', 'eikvakt-backup-manager' ); ?>
					<input type="number" min="1" max="100" class="small-text" name="files[full_every]" value="<?php echo esc_attr( (string) $ekbm_schedule['files']['full_every'] ); ?>">
					<?php esc_html_e( 'runs; incremental in between (only changed files).', 'eikvakt-backup-manager' ); ?>
				</p>
				<fieldset class="ekbm-components">
					<?php foreach ( Components::FILE_COMPONENTS as $ekbm_component ) : ?>
						<label><input type="checkbox" name="files[components][]" value="<?php echo esc_attr( $ekbm_component ); ?>" <?php checked( in_array( $ekbm_component, (array) $ekbm_schedule['files']['components'], true ) ); ?>> <?php echo esc_html( Components::label( $ekbm_component ) ); ?></label>
					<?php endforeach; ?>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Database backups', 'eikvakt-backup-manager' ); ?></th>
			<td>
				<?php $ekbm_controls( 'database', (array) $ekbm_schedule['database'] ); ?>
				<p class="description"><?php esc_html_e( 'Which tables are included is set on the Database tab.', 'eikvakt-backup-manager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Before updates', 'eikvakt-backup-manager' ); ?></th>
			<td>
				<label><input type="checkbox" name="pre_update[enabled]" value="1" <?php checked( ! empty( $ekbm_schedule['pre_update']['enabled'] ) ); ?>> <?php esc_html_e( 'Back up automatically before WordPress, a plugin or a theme is updated', 'eikvakt-backup-manager' ); ?></label><br>
				<label><input type="checkbox" name="pre_update[database]" value="1" <?php checked( ! empty( $ekbm_schedule['pre_update']['database'] ) ); ?>> <?php esc_html_e( 'Include the database', 'eikvakt-backup-manager' ); ?></label>
				<p class="description"><?php esc_html_e( 'Applies to manual, bulk and automatic background updates. Only what is about to change is archived, so the update is delayed as little as possible.', 'eikvakt-backup-manager' ); ?></p>
			</td>
		</tr>
	</table>
	<?php submit_button(); ?>
</form>
