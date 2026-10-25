<?php
/**
 * Plugin Name: Dev — keep the repository's scaffolding out of test backups
 * Description: Local development only. The repository root is mounted as the plugin directory, so it contains WordPress core, the database files and node_modules of the dev environment. Backing those up would recurse into a second WordPress and into MariaDB's data files.
 *
 * Lives OUTSIDE the plugin directory (dev/mu-plugins/, mounted only by
 * docker-compose) and is excluded from the release ZIP. It must never ship.
 *
 * @package AfritBackupManager
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'afbm_exclude_patterns',
	static function ( array $patterns ): array {
		if ( 'local' !== wp_get_environment_type() ) {
			return $patterns;
		}

		foreach ( [ 'local_wp_core', 'local_wp_target', 'db_data', 'dist', 'vendor', 'node_modules', 'dev', 'screenshots', 'docs', 'tests', '.git' ] as $dir ) {
			$patterns[] = 'content/plugins/afrit-backup-manager/' . $dir . '/*';
		}

		return $patterns;
	}
);
