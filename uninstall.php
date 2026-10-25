<?php
/**
 * Uninstall handler.
 *
 * Data is only destroyed when the administrator explicitly opted in via
 * Settings → General → Uninstall. Backups on this server are a second,
 * separate opt-in; backups on remote destinations are never touched.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/backup/class-components.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-util.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-paths.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-installer.php';

\EikvaktBackupManager\Installer::uninstall();
