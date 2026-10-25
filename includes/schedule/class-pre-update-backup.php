<?php
/**
 * Back up what is about to change right before WordPress, a plugin or a
 * theme is updated — manual updates, bulk updates and automatic background
 * updates alike.
 *
 * Hooked to `upgrader_pre_download`, which every upgrader runs before it
 * fetches the new package, so nothing has been touched yet. The backup runs
 * in the same request and the update waits for it: only the plugin or theme
 * being updated is archived (core updates archive core), plus the database
 * when so configured. A bulk update of ten plugins shares one database copy.
 *
 * A failing backup never blocks the update: losing an update because the
 * backup destination was unreachable would be the worse outcome. The failure
 * is reported like any other.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Pre_Update_Backup {

	/** Window in which a bulk update reuses the database copy just taken. */
	private const DB_REUSE = 15 * MINUTE_IN_SECONDS;

	public function register(): void {
		add_filter( 'upgrader_pre_download', [ $this, 'before_download' ], 10, 4 );
	}

	/**
	 * @param mixed  $reply      Short-circuit value; passed through untouched.
	 * @param string $package    Package URL.
	 * @param object $upgrader   Upgrader instance.
	 * @param mixed  $hook_extra Upgrade details.
	 * @return mixed
	 */
	public function before_download( $reply, $package, $upgrader, $hook_extra = [] ) {
		if ( false !== $reply || ! Settings::is_configured() ) {
			return $reply;
		}

		$settings = (array) ( Settings::get( Installer::OPTION_SCHEDULE )['pre_update'] ?? [] );
		if ( empty( $settings['enabled'] ) ) {
			return $reply;
		}

		/**
		 * Filters whether a backup runs before this update.
		 *
		 * @param bool   $run        Default true.
		 * @param object $upgrader   Upgrader instance.
		 * @param mixed  $hook_extra Upgrade details.
		 */
		if ( ! apply_filters( 'ekbm_backup_before_update', true, $upgrader, $hook_extra ) ) {
			return $reply;
		}

		[ $component, $only, $label ] = self::target( $upgrader, (array) $hook_extra );
		if ( '' === $component ) {
			return $reply;
		}

		$components = [ $component ];
		if ( ! empty( $settings['database'] ) && ! get_transient( 'ekbm_pre_update_db' ) ) {
			$components[] = Components::DATABASE;
			set_transient( 'ekbm_pre_update_db', 1, self::DB_REUSE );
		}

		if ( isset( $upgrader->skin ) && method_exists( $upgrader->skin, 'feedback' ) ) {
			/* translators: %s: what is being backed up */
			$upgrader->skin->feedback( sprintf( __( 'Backing up %s before the update&#8230;', 'eikvakt-backup-manager' ), $label ) );
		}

		try {
			$job = Job_Runner::run_now(
				'backup',
				[
					'components' => $components,
					'type'       => 'partial',
					'only'       => $only,
					/* translators: %s: plugin, theme or "WordPress" */
					'label'      => sprintf( __( 'Before updating %s', 'eikvakt-backup-manager' ), $label ),
				],
				'pre-update'
			);

			if ( isset( $upgrader->skin ) && method_exists( $upgrader->skin, 'feedback' ) ) {
				$upgrader->skin->feedback(
					Job_Runner::STATUS_DONE === $job['status']
						? __( 'Backup complete.', 'eikvakt-backup-manager' )
						: __( 'The backup failed; the update continues. See Backup Manager → Reports.', 'eikvakt-backup-manager' )
				);
			}
		} catch ( \Throwable $e ) {
			// Never block the update. The failure is in the report.
			unset( $e );
		}

		return $reply;
	}

	/**
	 * What to back up for an upgrader run.
	 *
	 * @param object               $upgrader   Upgrader.
	 * @param array<string, mixed> $hook_extra Upgrade details.
	 * @return array{0:string, 1:string[], 2:string} Component, limited paths, label.
	 */
	private static function target( $upgrader, array $hook_extra ): array {
		if ( $upgrader instanceof \Plugin_Upgrader || isset( $hook_extra['plugin'] ) ) {
			$plugin = (string) ( $hook_extra['plugin'] ?? '' );
			if ( '' === $plugin ) {
				return [ '', [], '' ];
			}
			$folder = dirname( $plugin );
			$path   = '.' === $folder ? 'content/plugins/' . $plugin : 'content/plugins/' . $folder;

			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$data = is_readable( WP_PLUGIN_DIR . '/' . $plugin ) ? get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false ) : [];

			return [ Components::PLUGINS, [ $path ], (string) ( $data['Name'] ?? $plugin ) ];
		}

		if ( $upgrader instanceof \Theme_Upgrader || isset( $hook_extra['theme'] ) ) {
			$theme = (string) ( $hook_extra['theme'] ?? '' );
			if ( '' === $theme ) {
				return [ '', [], '' ];
			}
			return [ Components::THEMES, [ 'content/themes/' . $theme ], wp_get_theme( $theme )->get( 'Name' ) ?: $theme ];
		}

		if ( $upgrader instanceof \Core_Upgrader ) {
			$paths = [ 'root/wp-admin', 'root/wp-includes' ];
			foreach ( (array) glob( ABSPATH . '*.php' ) as $file ) {
				$paths[] = 'root/' . basename( (string) $file );
			}
			return [ Components::CORE, $paths, 'WordPress' ];
		}

		return [ '', [], '' ];
	}
}
