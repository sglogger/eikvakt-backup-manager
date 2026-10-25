<?php
/**
 * Main plugin class – wires every component together.
 *
 * Components are plain objects with a single `register(): void` method that
 * does nothing but add hooks. Nothing happens at file-load time.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	/** @var Plugin|null */
	private static ?Plugin $instance = null;

	/** @var array<string, object> */
	private array $components = [];

	private bool $booted = false;

	private function __construct() {}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Spin everything up. Called once on `plugins_loaded`.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// No load_plugin_textdomain() call. Since WordPress 4.6 a translation is
		// loaded just in time on the first __() for the domain, and for a plugin
		// hosted on WordPress.org the .mo files are delivered into WP_LANG_DIR
		// and picked up from there. Calling it explicitly is discouraged and
		// Plugin Check flags it.

		// Converge the schema and options even when the plugin was updated by
		// uploading files rather than through the WordPress.org updater.
		Installer::maybe_migrate();

		// The job runner must answer on every request type: it is driven by
		// loopback requests to admin-ajax.php, by WP-Cron and by the admin
		// screen polling for progress.
		$this->add( 'job_runner', Job_Runner::instance() );
		$this->add( 'scheduler', new Scheduler() );
		$this->add( 'pre_update_backup', new Pre_Update_Backup() );
		$this->add( 'migration', new Migration() );

		// Admin surface. Everything user-visible is gated on `manage_options`
		// and, when configured, on the plugin password.
		if ( is_admin() ) {
			$this->add( 'password_gate', new Password_Gate() );
			$this->add( 'admin', new Admin() );
			$this->add( 'ajax', new Ajax() );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'eikvakt', Cli_Command::class );
		}

		do_action( 'ekbm_loaded', $this );
	}

	/**
	 * Register and immediately hook up a component.
	 */
	private function add( string $key, object $component ): void {
		$this->components[ $key ] = $component;

		if ( method_exists( $component, 'register' ) ) {
			$component->register();
		}
	}

	/**
	 * @return object|null
	 */
	public function component( string $key ) {
		return $this->components[ $key ] ?? null;
	}
}
