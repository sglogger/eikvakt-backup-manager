<?php
/**
 * Handlers for every form on the plugin's screens.
 *
 * Called by Admin::handle_post() after the capability, nonce and password
 * checks, with the form already sanitised by Admin::request(). Each handler
 * then validates its fields: ranges, allow-lists and formats.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Actions {

	/**
	 * @param array<string, mixed> $post Sanitised POST data.
	 */
	public static function dispatch( string $action, array $post ): void {
		switch ( $action ) {
			case 'run_setup':
				Setup::run();
				Admin::redirect( Admin::MENU_DASHBOARD, 'setup_done' );
				break;

			case 'backup_now':
				self::backup_now( $post );
				break;

			case 'cancel_job':
				Job_Runner::cancel();
				Admin::redirect( Admin::MENU_DASHBOARD, 'cancelled' );
				break;

			case 'delete_backup':
				$id = sanitize_text_field( (string) ( $post['backup_id'] ?? '' ) );
				if ( ! Manifest::is_valid_id( $id ) ) {
					Admin::redirect( Admin::MENU_BACKUPS, 'invalid' );
				}
				$ids = array_merge( [ $id ], Catalog::dependents( $id ) );

				// A kept backup is never deleted as a side effect: unmark it first.
				if ( [] !== Catalog::kept_among( $ids ) ) {
					Admin::redirect( Admin::MENU_BACKUPS, 'keep_blocks_delete' );
				}

				$ok = true;
				foreach ( $ids as $one ) {
					$ok = Retention::delete( $one ) && $ok;
				}
				Admin::redirect( Admin::MENU_BACKUPS, $ok ? 'deleted' : 'delete_partial' );
				break;

			case 'toggle_keep':
				$id   = sanitize_text_field( (string) ( $post['backup_id'] ?? '' ) );
				$keep = ! empty( $post['keep'] );
				if ( ! Manifest::is_valid_id( $id ) || ! Catalog::set_keep( $id, $keep ) ) {
					Admin::redirect( Admin::MENU_BACKUPS, 'invalid' );
				}
				Admin::redirect( Admin::MENU_BACKUPS, $keep ? 'kept' : 'unkept' );
				break;

			case 'rescan':
				Catalog::rescan_local();
				foreach ( array_keys( Destinations::enabled() ) as $id ) {
					try {
						Destinations::rescan( (string) $id );
					} catch ( \Throwable $e ) {
						Admin::set_flash( Util::message( $e ) );
					}
				}
				Admin::redirect( Admin::MENU_BACKUPS, 'rescanned' );
				break;

			case 'start_restore':
				self::start_restore( $post );
				break;

			case 'migration_backup':
				Job_Runner::enqueue(
					'backup',
					[
						'components' => Components::ALL,
						'type'       => 'full',
						'label'      => __( 'Migration package', 'eikvakt-backup-manager' ),
					],
					'migration'
				);
				Admin::redirect( Admin::MENU_MIGRATION, 'backup_started' );
				break;

			case 'create_key':
				$key = Migration::create_key( sanitize_text_field( (string) ( $post['backup_id'] ?? '' ) ) );
				if ( '' === $key ) {
					Admin::redirect( Admin::MENU_MIGRATION, 'key_failed' );
				}
				set_transient( 'ekbm_new_key_' . get_current_user_id(), Secret_Cipher::encrypt( $key ), 10 * MINUTE_IN_SECONDS );
				Admin::redirect( Admin::MENU_MIGRATION, 'key_created' );
				break;

			case 'revoke_keys':
				Migration::revoke_all();
				Admin::redirect( Admin::MENU_MIGRATION, 'keys_revoked' );
				break;

			case 'import_key':
				$key = trim( (string) ( $post['migration_key'] ?? '' ) );
				if ( null === Migration::parse_key( $key ) ) {
					Admin::redirect( Admin::MENU_MIGRATION, 'key_invalid' );
				}
				Job_Runner::enqueue(
					'pull',
					[
						'key'           => Secret_Cipher::encrypt( $key ),
						'allow_private' => ! empty( $post['allow_private'] ),
					],
					'migration'
				);
				Admin::redirect( Admin::MENU_MIGRATION, 'import_started' );
				break;

			case 'save_general':
				self::save_general( $post );
				break;

			case 'save_schedule':
				self::save_schedule( $post );
				break;

			case 'save_destination':
				self::save_destination( $post );
				break;

			case 'delete_destination':
				Destinations::delete( sanitize_key( (string) ( $post['destination'] ?? '' ) ) );
				self::back( 'storage', 'dest_deleted' );
				break;

			case 'test_destination':
				$result = Destinations::test( sanitize_key( (string) ( $post['destination'] ?? '' ) ) );
				if ( is_wp_error( $result ) ) {
					Admin::set_flash( $result->get_error_message() );
				}
				self::back( 'storage', is_wp_error( $result ) ? 'test_failed' : 'test_ok' );
				break;

			case 'oauth_connect':
				$id          = sanitize_key( (string) ( $post['destination'] ?? '' ) );
				$destination = Destinations::get( $id );

				// Plugin's Dropbox app: no redirect back here is possible, so
				// show the "open Dropbox, paste the code" form instead.
				if ( null !== $destination && 'dropbox' === $destination['type'] && Storage_Dropbox::uses_plugin_app( (array) $destination['config'] ) ) {
					$flow = OAuth::begin_paste( $id );
					if ( null === $flow ) {
						self::back( 'storage', 'oauth_failed' );
					}
					wp_safe_redirect( admin_url( 'admin.php?page=' . Admin::MENU_SETTINGS . '&tab=storage&connect=' . $flow['state'] ) );
					exit;
				}

				$url = OAuth::begin( $id );
				if ( '' === $url ) {
					self::back( 'storage', 'oauth_failed' );
				}
				// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the provider's authorisation page (dropbox.com / microsoftonline.com), built from fixed hosts.
				wp_redirect( $url );
				exit;

			case 'oauth_paste':
				$state = sanitize_key( (string) ( $post['state'] ?? '' ) );
				$code  = sanitize_text_field( (string) ( $post['code'] ?? '' ) );
				self::back( 'storage', OAuth::complete( $state, $code ) );
				break;

			case 'save_files':
				$settings = Settings::general();
				$rules    = json_decode( (string) ( $post['file_rules'] ?? '' ), true );

				$settings['file_rules']             = File_Rules::sanitize( is_array( $rules ) ? $rules : [] );
				$settings['excludes']               = sanitize_textarea_field( (string) ( $post['excludes'] ?? '' ) );
				$settings['exclude_larger_than_mb'] = max( 0, min( 102400, (int) ( $post['exclude_larger_than_mb'] ?? 0 ) ) );

				Settings::update( Installer::OPTION_SETTINGS, $settings );
				self::back( 'files', 'saved' );
				break;

			case 'save_database':
				$tables = array_values( array_intersect( Database_Dumper::site_tables(), array_map( 'strval', (array) ( $post['tables'] ?? [] ) ) ) );
				Settings::update(
					Installer::OPTION_DATABASE,
					[
						'mode'   => 'selected' === ( $post['mode'] ?? '' ) ? 'selected' : 'all',
						'tables' => $tables,
					]
				);
				self::back( 'database', 'saved' );
				break;

			case 'save_encryption':
				self::save_encryption( $post );
				break;

			case 'save_retention':
				self::save_retention( $post );
				break;

			case 'save_security':
				self::save_security( $post );
				break;

			case 'check_exposure':
				Paths::check_exposure();
				self::back( 'security', 'exposure_checked' );
				break;

			case 'test_email':
				$run = [
					'id'           => 0,
					'kind'         => 'backup',
					'status'       => Run_Repository::STATUS_SUCCESS,
					'run_trigger'  => 'manual',
					'started_at'   => gmdate( 'Y-m-d H:i:s' ),
					'backup_id'    => '',
					'components'   => '',
					'size_bytes'   => 0,
					'destinations' => '',
					'summary'      => __( 'This is a test message from Eikvakt Backup Manager.', 'eikvakt-backup-manager' ),
				];
				self::back( 'general', Email_Report::send( $run ) ? 'mail_ok' : 'mail_failed' );
				break;
		}

		Admin::redirect( Admin::MENU_DASHBOARD, 'invalid' );
	}

	private static function back( string $tab, string $notice, array $args = [] ): void {
		Admin::redirect( Admin::MENU_SETTINGS, $notice, array_merge( [ 'tab' => $tab ], $args ) );
	}

	/**
	 * @param array<string, mixed> $post POST data.
	 */
	private static function backup_now( array $post ): void {
		$components = Components::sanitize( $post['components'] ?? [] );
		if ( [] === $components ) {
			Admin::redirect( Admin::MENU_DASHBOARD, 'nothing' );
		}

		$type = 'partial';
		if ( [] === Components::file_components( $components ) ) {
			$type = 'database';
		} elseif ( Components::ALL === $components ) {
			$type = 'full';
		} elseif ( Components::FILE_COMPONENTS === $components ) {
			// Exactly what the file schedule backs up: continue its chain.
			$type = 'auto';
		}

		$schedule = Settings::get( Installer::OPTION_SCHEDULE );

		Job_Runner::enqueue(
			'backup',
			[
				'components' => $components,
				'type'       => $type,
				'label'      => sanitize_text_field( (string) ( $post['label'] ?? '' ) ),
				'full_every' => max( 1, (int) ( $schedule['files']['full_every'] ?? 7 ) ),
			],
			'manual'
		);

		Admin::redirect( Admin::MENU_DASHBOARD, 'backup_started' );
	}

	/**
	 * @param array<string, mixed> $post POST data.
	 */
	private static function start_restore( array $post ): void {
		$id       = sanitize_text_field( (string) ( $post['backup_id'] ?? '' ) );
		$manifest = Catalog::get( $id );
		if ( null === $manifest ) {
			Admin::redirect( Admin::MENU_BACKUPS, 'invalid' );
		}

		$components = array_values( array_intersect( $manifest->components(), Components::sanitize( $post['components'] ?? [] ) ) );
		if ( [] === $components ) {
			Admin::redirect( Admin::MENU_RESTORE, 'nothing', [ 'backup' => $id ] );
		}

		$pairs = [];
		foreach ( (array) ( $post['replace_from'] ?? [] ) as $i => $from ) {
			$from = trim( (string) $from );
			$to   = trim( (string) ( $post['replace_to'][ $i ] ?? '' ) );
			if ( '' !== $from && $from !== $to ) {
				// Raw text on purpose: URLs and paths must be replaced verbatim.
				$pairs[] = [ wp_check_invalid_utf8( $from ), wp_check_invalid_utf8( $to ) ];
			}
		}

		$passphrase = (string) ( $post['passphrase'] ?? '' );
		if ( $manifest->is_encrypted() ) {
			if ( '' === $passphrase ) {
				$passphrase = Secret_Cipher::decrypt( (string) Settings::get( Installer::OPTION_ENCRYPTION )['passphrase'] );
			}
			if ( ! self::passphrase_matches( $manifest, $passphrase ) ) {
				Admin::redirect( Admin::MENU_RESTORE, 'passphrase', [ 'backup' => $id ] );
			}
		}

		if ( ! empty( $post['safety'] ) ) {
			Job_Runner::enqueue(
				'backup',
				[
					'components' => $components,
					'type'       => Components::ALL === $components ? 'full' : ( [] === Components::file_components( $components ) ? 'database' : 'partial' ),
					'label'      => __( 'Before restore', 'eikvakt-backup-manager' ),
				],
				'safety'
			);
		}

		Job_Runner::enqueue(
			'restore',
			[
				'backup_id'    => $id,
				'components'   => $components,
				'tables'       => array_map( 'strval', (array) ( $post['tables'] ?? [] ) ),
				'source'       => sanitize_key( (string) ( $post['source'] ?? '' ) ),
				'passphrase'   => Secret_Cipher::encrypt( $passphrase ),
				'replace'      => $pairs,
				'delete_extra' => ! empty( $post['delete_extra'] ),
				'wp_config'    => ! empty( $post['wp_config'] ),
			],
			'manual'
		);

		Admin::redirect( Admin::MENU_DASHBOARD, 'restore_started' );
	}

	/**
	 * Check a passphrase against a locally available encrypted file of the
	 * set. When no file is local yet, the check happens during the restore.
	 */
	private static function passphrase_matches( Manifest $manifest, string $passphrase ): bool {
		if ( '' === $passphrase ) {
			return false;
		}
		foreach ( $manifest->files() as $file ) {
			$path = Paths::backup_dir() . '/' . $file['name'];
			if ( str_ends_with( $file['name'], Encryptor::EXTENSION ) && is_file( $path ) ) {
				return Encryptor::verify( $path, $passphrase );
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $post POST data.
	 */
	private static function save_general( array $post ): void {
		$settings = Settings::general();
		$notice   = 'saved';

		$path = trim( (string) ( $post['backup_path'] ?? '' ) );
		if ( $path !== (string) $settings['backup_path'] ) {
			$normalized = '' === $path ? '' : untrailingslashit( wp_normalize_path( $path ) );
			$problem    = '' === $normalized ? '' : Paths::validate_custom_dir( $normalized );

			if ( '' === $problem ) {
				$settings['backup_path'] = $normalized;
			} else {
				$notice = $problem;
			}
		}

		$settings['part_size_mb']                = max( 10, min( 4000, (int) ( $post['part_size_mb'] ?? 250 ) ) );
		$settings['keep_local']                  = ! empty( $post['keep_local'] );
		$settings['time_budget']                 = max( 5, min( 300, (int) ( $post['time_budget'] ?? 20 ) ) );
		$settings['email_mode']                  = in_array( $post['email_mode'] ?? '', [ 'always', 'failures', 'never' ], true ) ? (string) $post['email_mode'] : 'failures';
		$settings['email_recipients']            = sanitize_textarea_field( (string) ( $post['email_recipients'] ?? '' ) );
		$settings['delete_data_on_uninstall']    = ! empty( $post['delete_data_on_uninstall'] );
		$settings['delete_backups_on_uninstall'] = ! empty( $post['delete_backups_on_uninstall'] );

		Settings::update( Installer::OPTION_SETTINGS, $settings );
		Catalog::flush_cache();

		if ( 'saved' === $notice ) {
			Paths::ensure();
			Catalog::rescan_local();
			Paths::check_exposure();
		}

		self::back( 'general', $notice );
	}

	/**
	 * @param array<string, mixed> $post POST data.
	 */
	private static function save_schedule( array $post ): void {
		$schedule = Settings::get( Installer::OPTION_SCHEDULE );
		$before   = $schedule;

		foreach ( Scheduler::KINDS as $kind ) {
			$in = (array) ( $post[ $kind ] ?? [] );

			$schedule[ $kind ]['frequency']      = in_array( $in['frequency'] ?? '', Schedule::FREQUENCIES, true ) ? (string) $in['frequency'] : 'off';
			$schedule[ $kind ]['interval_hours'] = max( 1, min( 168, (int) ( $in['interval_hours'] ?? 12 ) ) );
			$schedule[ $kind ]['time']           = sprintf( '%02d:%02d', ...Schedule::parse_time( (string) ( $in['time'] ?? '02:00' ) ) );
			$schedule[ $kind ]['weekday']        = max( 1, min( 7, (int) ( $in['weekday'] ?? 1 ) ) );
			$schedule[ $kind ]['monthday']       = max( 1, min( 31, (int) ( $in['monthday'] ?? 1 ) ) );

			if ( 'files' === $kind ) {
				$schedule['files']['full_every'] = max( 1, min( 100, (int) ( $in['full_every'] ?? 7 ) ) );
				$components                      = Components::sanitize( $in['components'] ?? [], false );
				$schedule['files']['components'] = [] === $components ? Components::FILE_COMPONENTS : $components;
			}

			if ( $schedule[ $kind ] !== $before[ $kind ] ) {
				Scheduler::anchor( $kind );
			}
		}

		$schedule['pre_update'] = [
			'enabled'  => ! empty( $post['pre_update']['enabled'] ),
			'database' => ! empty( $post['pre_update']['database'] ),
		];

		Settings::update( Installer::OPTION_SCHEDULE, $schedule );

		$general               = Settings::general();
		$general['configured'] = true;
		Settings::update( Installer::OPTION_SETTINGS, $general );

		self::back( 'schedule', 'saved' );
	}

	/**
	 * @param array<string, mixed> $post POST data.
	 */
	private static function save_destination( array $post ): void {
		$type  = sanitize_key( (string) ( $post['type'] ?? '' ) );
		$types = Destinations::types();
		if ( ! isset( $types[ $type ] ) ) {
			self::back( 'storage', 'invalid' );
		}

		$id          = sanitize_key( (string) ( $post['destination'] ?? '' ) );
		$existing    = '' !== $id ? Destinations::get( $id ) : null;
		$destination = $existing ?? [
			'id'     => 'd' . Util::random_token( 4 ),
			'type'   => $type,
			'config' => [],
		];

		if ( $destination['type'] !== $type ) {
			self::back( 'storage', 'invalid' );
		}

		$destination['name']           = sanitize_text_field( (string) ( $post['name'] ?? '' ) ) ?: $types[ $type ]['label'];
		$destination['enabled']        = ! empty( $post['enabled'] );
		$destination['retention_days'] = max( 0, min( 3650, (int) ( $post['retention_days'] ?? 0 ) ) );

		$config = (array) $destination['config'];
		$in     = (array) ( $post['config'] ?? [] );

		foreach ( $types[ $type ]['fields'] as $field => $kind ) {
			$secret = in_array( $field, $types[ $type ]['secrets'], true );

			if ( 'checkbox' === $kind ) {
				$config[ $field ] = ! empty( $in[ $field ] );
				continue;
			}

			$value = (string) ( $in[ $field ] ?? '' );

			if ( $secret ) {
				// Blank keeps the stored secret; the field is never pre-filled.
				if ( '' !== $value ) {
					$config[ $field ] = Secret_Cipher::encrypt( 'textarea' === $kind ? sanitize_textarea_field( $value ) : $value );
				}
				if ( ! empty( $in[ $field . '_clear' ] ) ) {
					$config[ $field ] = '';
				}
				continue;
			}

			switch ( $kind ) {
				case 'number':
					$config[ $field ] = max( 0, min( 65535, (int) $value ) );
					break;
				default:
					$config[ $field ] = sanitize_text_field( $value );
			}
		}

		// Changing the app identity invalidates the existing connection.
		if ( null !== $existing && $types[ $type ]['oauth'] ) {
			foreach ( [ 'own_app', 'app_key', 'client_id', 'tenant' ] as $identity ) {
				if ( ( $existing['config'][ $identity ] ?? '' ) !== ( $config[ $identity ] ?? '' ) ) {
					$config['refresh_token'] = '';
					delete_transient( 'ekbm_token_' . $destination['id'] );
				}
			}
		}

		$destination['config'] = $config;
		Destinations::save( $destination );

		self::back( 'storage', 'dest_saved' );
	}

	/**
	 * @param array<string, mixed> $post POST data.
	 */
	private static function save_encryption( array $post ): void {
		$settings = Settings::get( Installer::OPTION_ENCRYPTION );

		$enabled    = ! empty( $post['enabled'] );
		$passphrase = (string) ( $post['passphrase'] ?? '' );
		$confirm    = (string) ( $post['passphrase_confirm'] ?? '' );

		if ( '' !== $passphrase || ( $enabled && '' === (string) $settings['passphrase'] ) ) {
			if ( mb_strlen( $passphrase ) < 12 || $passphrase !== $confirm ) {
				self::back( 'encryption', 'passphrase_short' );
			}
			$settings['passphrase'] = Secret_Cipher::encrypt( $passphrase );
		}

		$settings['enabled'] = $enabled;
		$settings['files']   = ! empty( $post['files'] );
		$settings['hint']    = sanitize_text_field( (string) ( $post['hint'] ?? '' ) );

		Settings::update( Installer::OPTION_ENCRYPTION, $settings );
		self::back( 'encryption', 'saved' );
	}

	/**
	 * @param array<string, mixed> $post POST data.
	 */
	private static function save_retention( array $post ): void {
		$int = static fn( string $key, int $min, int $max, int $default ): int => max( $min, min( $max, (int) ( $post[ $key ] ?? $default ) ) );

		Settings::update(
			Installer::OPTION_RETENTION,
			[
				'keep_chains'    => $int( 'keep_chains', 1, 365, 4 ),
				'keep_database'  => $int( 'keep_database', 1, 3650, 14 ),
				'keep_partial'   => $int( 'keep_partial', 1, 365, 10 ),
				'max_age_days'   => $int( 'max_age_days', 0, 3650, 0 ),
				'gfs_enabled'    => ! empty( $post['gfs_enabled'] ),
				'gfs_daily'      => $int( 'gfs_daily', 0, 365, 7 ),
				'gfs_weekly'     => $int( 'gfs_weekly', 0, 260, 4 ),
				'gfs_monthly'    => $int( 'gfs_monthly', 0, 120, 6 ),
				'local_chains'   => $int( 'local_chains', 0, 365, 0 ),
				'local_database' => $int( 'local_database', 0, 3650, 0 ),
				'local_days'     => $int( 'local_days', 0, 3650, 0 ),
				'remote_days'    => $int( 'remote_days', 0, 3650, 0 ),
			]
		);

		if ( ! empty( $post['apply_now'] ) ) {
			Retention::apply();
		}

		self::back( 'retention', 'saved' );
	}

	/**
	 * @param array<string, mixed> $post POST data.
	 */
	private static function save_security( array $post ): void {
		$security = Settings::get( Installer::OPTION_SECURITY );

		$security['session_minutes'] = max( 5, min( 720, (int) ( $post['session_minutes'] ?? 30 ) ) );
		Settings::update( Installer::OPTION_SECURITY, $security );

		$current  = (string) ( $post['current_password'] ?? '' );
		$new      = (string) ( $post['new_password'] ?? '' );
		$confirm  = (string) ( $post['new_password_confirm'] ?? '' );
		$disable  = ! empty( $post['disable_password'] );
		$has_pass = '' !== (string) $security['password_hash'] && ! empty( $security['password_enabled'] );

		if ( ! $disable && '' === $new ) {
			self::back( 'security', 'saved' );
		}

		// Changing or removing an existing password needs the current one —
		// unless the wp-config.php recovery constant is set.
		$recovery = defined( 'EKBM_DISABLE_PASSWORD' ) && EKBM_DISABLE_PASSWORD;
		if ( $has_pass && ! $recovery && ! Password_Gate::verify( $current ) ) {
			self::back( 'security', 'password_wrong' );
		}

		if ( $disable ) {
			$security['password_enabled'] = false;
			$security['password_hash']    = '';
			Settings::update( Installer::OPTION_SECURITY, $security );
			Password_Gate::lock();
			self::back( 'security', 'password_off' );
		}

		if ( mb_strlen( $new ) < 10 || $new !== $confirm ) {
			self::back( 'security', 'password_short' );
		}

		Password_Gate::set_password( $new );
		Password_Gate::unlock();
		self::back( 'security', 'password_set' );
	}
}
