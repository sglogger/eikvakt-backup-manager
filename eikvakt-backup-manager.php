<?php
/**
 * Plugin Name:       Afrit Backup Manager
 * Plugin URI:        https://github.com/sglogger/afrit-backup-manager
 * Description:       Backups, restores and migrations for the whole WordPress installation ("afrit" is Icelandic for "copy"). Incremental file backups, selectable database tables, encryption, scheduling, retention rules and simultaneous upload to this server, FTP, SFTP, Dropbox and OneDrive. Restore files, database, plugins or themes separately, or move the site to a new domain. There is no PRO version. All free.
 * Version:           1.0.3
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Steven Glogger
 * Author URI:        https://www.glogger.ch
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       afrit-backup-manager
 *
 * @package AfritBackupManager
 */

declare( strict_types = 1 );

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -----------------------------------------------------------------------------
// Plugin constants
// -----------------------------------------------------------------------------
define( 'AFBM_VERSION', '1.0.3' );
define( 'AFBM_FILE', __FILE__ );
define( 'AFBM_DIR', plugin_dir_path( __FILE__ ) );
define( 'AFBM_URL', plugin_dir_url( __FILE__ ) );
define( 'AFBM_BASENAME', plugin_basename( __FILE__ ) );

// Minimum platform versions. Kept as constants so the guards, the admin notice
// and readme.txt can all be checked against a single source.
define( 'AFBM_MIN_PHP', '8.1' );
define( 'AFBM_MIN_WP', '6.5' );

// Internal data-version constant – used by the migrator to know whether the
// schema or stored options need patching up after an upgrade. Bumped
// independently of the plugin version.
define( 'AFBM_DATA_VERSION', '1.0' );

// Version of the on-disk backup format (manifest, index, archive layout). A
// restore refuses a set written in a newer format than it understands.
define( 'AFBM_FORMAT_VERSION', 1 );

// -----------------------------------------------------------------------------
// Platform guards
//
// A backup plugin that fatals on an old platform is worse than one that
// disables itself loudly, so both guards return early with an admin notice.
// The text domain is not loaded yet at this point, so these two notices are
// deliberately untranslated — they must render even when nothing else works.
// -----------------------------------------------------------------------------
if ( version_compare( PHP_VERSION, AFBM_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			printf(
			/* translators: 1: required PHP version, 2: current PHP version */
				esc_html( 'Afrit Backup Manager requires PHP %1$s or newer. You are running %2$s. The plugin has been disabled.' ),
				esc_html( AFBM_MIN_PHP ),
				esc_html( PHP_VERSION )
			);
			echo '</p></div>';
		}
	);
	return;
}

if ( version_compare( (string) get_bloginfo( 'version' ), AFBM_MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			printf(
			/* translators: 1: required WordPress version, 2: current WordPress version */
				esc_html( 'Afrit Backup Manager requires WordPress %1$s or newer. You are running %2$s. The plugin has been disabled.' ),
				esc_html( AFBM_MIN_WP ),
				esc_html( (string) get_bloginfo( 'version' ) )
			);
			echo '</p></div>';
		}
	);
	return;
}

// -----------------------------------------------------------------------------
// Class files, in dependency order. No autoloader in production by design —
// see README "Coding conventions". Composer's autoloader is only pulled in
// lazily by the SFTP driver, for phpseclib.
// -----------------------------------------------------------------------------
require_once AFBM_DIR . 'includes/class-plugin.php';
require_once AFBM_DIR . 'includes/class-installer.php';
require_once AFBM_DIR . 'includes/class-settings.php';
require_once AFBM_DIR . 'includes/class-secret-cipher.php';
require_once AFBM_DIR . 'includes/class-paths.php';
require_once AFBM_DIR . 'includes/class-util.php';

// Reports: every job writes one run row with its log.
require_once AFBM_DIR . 'includes/report/class-run-repository.php';
require_once AFBM_DIR . 'includes/report/class-email-report.php';

// Encryption. Free of WordPress so the stream format can be unit-tested.
require_once AFBM_DIR . 'includes/crypto/class-encryptor.php';

// Storage destinations.
require_once AFBM_DIR . 'includes/storage/interface-storage-driver.php';
require_once AFBM_DIR . 'includes/storage/class-oauth.php';
require_once AFBM_DIR . 'includes/storage/class-storage-local.php';
require_once AFBM_DIR . 'includes/storage/class-storage-ftp.php';
require_once AFBM_DIR . 'includes/storage/class-storage-sftp.php';
require_once AFBM_DIR . 'includes/storage/class-storage-dropbox.php';
require_once AFBM_DIR . 'includes/storage/class-storage-onedrive.php';
require_once AFBM_DIR . 'includes/storage/class-destinations.php';

// Backup building blocks. Components, File_Index, Manifest and the policy
// classes are free of WordPress so they can be tested on their own.
require_once AFBM_DIR . 'includes/backup/class-components.php';
require_once AFBM_DIR . 'includes/backup/class-manifest.php';
require_once AFBM_DIR . 'includes/backup/class-catalog.php';
require_once AFBM_DIR . 'includes/backup/class-file-index.php';
require_once AFBM_DIR . 'includes/backup/class-file-rules.php';
require_once AFBM_DIR . 'includes/backup/class-file-scanner.php';
require_once AFBM_DIR . 'includes/backup/class-archiver.php';
require_once AFBM_DIR . 'includes/backup/class-database-dumper.php';

// Job engine and the jobs it runs.
require_once AFBM_DIR . 'includes/engine/interface-job-handler.php';
require_once AFBM_DIR . 'includes/engine/class-job-store.php';
require_once AFBM_DIR . 'includes/engine/class-job-runner.php';
require_once AFBM_DIR . 'includes/engine/class-transfer.php';
require_once AFBM_DIR . 'includes/backup/class-backup-job.php';
require_once AFBM_DIR . 'includes/restore/class-search-replace.php';
require_once AFBM_DIR . 'includes/restore/class-sql-importer.php';
require_once AFBM_DIR . 'includes/restore/class-restore-job.php';
require_once AFBM_DIR . 'includes/migration/class-migration.php';
require_once AFBM_DIR . 'includes/migration/class-pull-job.php';

// Scheduling and retention.
require_once AFBM_DIR . 'includes/schedule/class-schedule.php';
require_once AFBM_DIR . 'includes/schedule/class-scheduler.php';
require_once AFBM_DIR . 'includes/schedule/class-pre-update-backup.php';
require_once AFBM_DIR . 'includes/retention/class-retention-policy.php';
require_once AFBM_DIR . 'includes/retention/class-retention.php';

// Access control and onboarding.
require_once AFBM_DIR . 'includes/access/class-password-gate.php';
require_once AFBM_DIR . 'includes/setup/class-setup.php';

// Admin surface.
require_once AFBM_DIR . 'admin/class-admin.php';
require_once AFBM_DIR . 'admin/class-admin-actions.php';
require_once AFBM_DIR . 'admin/class-ajax.php';

// The list tables extend WP_List_Table, which only exists in the admin.
if ( is_admin() ) {
	require_once AFBM_DIR . 'admin/class-backups-list-table.php';
	require_once AFBM_DIR . 'admin/class-runs-list-table.php';
}

// WP-CLI.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AFBM_DIR . 'includes/cli/class-cli-command.php';
}

// -----------------------------------------------------------------------------
// Lifecycle hooks
// -----------------------------------------------------------------------------
register_activation_hook( __FILE__, [ \AfritBackupManager\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \AfritBackupManager\Installer::class, 'deactivate' ] );

// -----------------------------------------------------------------------------
// Boot
// -----------------------------------------------------------------------------
add_action(
	'plugins_loaded',
	static function () {
		\AfritBackupManager\Plugin::instance()->boot();
	}
);
