<?php
/**
 * Where backups live, and making sure nobody can fetch them over HTTP.
 *
 * A backup holds the complete database — password hashes, e-mail addresses,
 * every secret any plugin ever stored. A world-readable backup archive is the
 * classic way WordPress sites get breached through their backup plugin, so
 * the storage location is defended in layers:
 *
 *   1. Outside the web root whenever the server allows it. Nothing there can
 *      be requested by URL at all.
 *   2. Otherwise a directory with a random, unguessable name, locked down
 *      with .htaccess (Apache, LiteSpeed), web.config (IIS) and index files
 *      against directory listings.
 *   3. Every archive name carries its own random component, so even a
 *      server that ignores all of the above does not hand out a predictable
 *      URL.
 *   4. A self-test that actually requests a probe file from the backup
 *      directory over HTTP and raises an alarm on the dashboard if the
 *      server served it (nginx ignores .htaccess).
 *
 * Archives are only ever handed out by the authenticated download endpoint
 * in the admin, never by URL.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Paths {

	public const PROBE_PREFIX = 'ekbm-probe-';

	/** Marks a directory as ours. Only such a directory is ever written to or deleted. */
	public const MARKER = '.ekbm-backup-dir';

	/** Appears in every protection file we write; anything without it is not ours. */
	private const SIGNATURE = 'Eikvakt Backup Manager';

	/**
	 * Absolute path of the backup directory, without a trailing slash.
	 */
	public static function backup_dir(): string {
		$settings = (array) get_option( Installer::OPTION_SETTINGS, [] );
		$custom   = trim( (string) ( $settings['backup_path'] ?? '' ) );

		if ( '' !== $custom ) {
			return untrailingslashit( wp_normalize_path( $custom ) );
		}

		$resolved = trim( (string) ( $settings['resolved_dir'] ?? '' ) );
		if ( '' !== $resolved ) {
			return untrailingslashit( $resolved );
		}

		return self::default_dir();
	}

	/**
	 * Scratch space for running jobs: scan lists, decrypted archives,
	 * downloaded parts, job state. Lives inside the protected backup dir.
	 */
	public static function work_dir(): string {
		return self::backup_dir() . '/.work';
	}

	/**
	 * First choice: next to the WordPress directory, outside the web root.
	 * Fallback: a randomly named directory under uploads.
	 */
	public static function default_dir(): string {
		$key = (string) get_option( Installer::OPTION_STORAGE_KEY, '' );
		if ( '' === $key ) {
			$key = 'default';
		}

		$outside = wp_normalize_path( dirname( untrailingslashit( ABSPATH ) ) ) . '/ekbm-backups-' . $key;

		if ( self::can_use_outside( $outside ) ) {
			return $outside;
		}

		$uploads = wp_get_upload_dir();
		return untrailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) ) . '/ekbm-backups-' . $key;
	}

	/**
	 * Is a directory outside the document root usable?
	 */
	private static function can_use_outside( string $dir ): bool {
		$parent = dirname( $dir );

		if ( ! self::open_basedir_allows( $parent ) ) {
			return false;
		}

		// When the document root is unknown (CLI, cron) the parent might still
		// be the web root itself — e.g. WordPress in a subdirectory. Only use it
		// when we can positively tell it is not served.
		if ( self::is_served( $dir ) !== false ) {
			return false;
		}

		return is_dir( $dir ) ? wp_is_writable( $dir ) : wp_is_writable( $parent );
	}

	/**
	 * Is the path under the web server's document root?
	 *
	 * @return bool|null True/false, or null when the document root is unknown.
	 */
	public static function is_served( string $path ): ?bool {
		$docroot = isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['DOCUMENT_ROOT'] ) ) : '';

		if ( '' === $docroot ) {
			return null;
		}

		$docroot = realpath( $docroot );
		if ( false === $docroot ) {
			return null;
		}

		$docroot = trailingslashit( wp_normalize_path( $docroot ) );
		$path    = trailingslashit( wp_normalize_path( $path ) );

		return str_starts_with( $path, $docroot );
	}

	private static function open_basedir_allows( string $path ): bool {
		$restriction = (string) ini_get( 'open_basedir' );
		if ( '' === $restriction ) {
			return true;
		}

		$path = trailingslashit( wp_normalize_path( $path ) );
		foreach ( explode( PATH_SEPARATOR, $restriction ) as $allowed ) {
			$allowed = trailingslashit( wp_normalize_path( $allowed ) );
			if ( str_starts_with( $path, $allowed ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Create the backup and work directories and lock them down. Safe to call
	 * on every activation and before every job.
	 */
	public static function ensure(): bool {
		$settings = (array) get_option( Installer::OPTION_SETTINGS, [] );

		if ( '' === trim( (string) ( $settings['backup_path'] ?? '' ) ) && '' === trim( (string) ( $settings['resolved_dir'] ?? '' ) ) ) {
			$settings['resolved_dir'] = self::default_dir();
			update_option( Installer::OPTION_SETTINGS, $settings, false );
		}

		$dir = self::backup_dir();

		foreach ( [ $dir, self::work_dir() ] as $path ) {
			if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
				return false;
			}
			self::protect( $path );
		}

		if ( ! is_file( $dir . '/' . self::MARKER ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- marker file in our own directory; WP_Filesystem is unavailable in cron.
			@file_put_contents( $dir . '/' . self::MARKER, self::SIGNATURE . " backup directory. Do not store other files here; uninstalling the plugin may delete this folder.\n" );
		}

		return true;
	}

	public static function is_ours( string $dir ): bool {
		return is_file( untrailingslashit( $dir ) . '/' . self::MARKER );
	}

	/**
	 * Check a backup directory entered by an administrator.
	 *
	 * The plugin writes deny-all .htaccess/web.config/index files into its
	 * directory and deletes the directory on uninstall (when so configured),
	 * so it must be a folder of its own: never WordPress itself, wp-content,
	 * uploads, plugins or themes, never a parent of those, and never an
	 * existing folder that already contains other files.
	 *
	 * @return string '' when acceptable, otherwise a notice key.
	 */
	public static function validate_custom_dir( string $path ): string {
		$path = untrailingslashit( wp_normalize_path( $path ) );

		if ( '' === $path || ! path_is_absolute( $path ) || 1 === preg_match( '#(^|/)\.\.?(/|$)|[\x00-\x1f]#', $path ) ) {
			return 'path_invalid';
		}

		$canonical = self::canonical( $path );
		$uploads   = wp_get_upload_dir();

		$protected = array_filter(
			array_map(
				[ self::class, 'canonical' ],
				[
					ABSPATH,
					WP_CONTENT_DIR,
					(string) $uploads['basedir'],
					WP_PLUGIN_DIR,
					defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
					get_theme_root(),
				]
			)
		);

		foreach ( $protected as $dir ) {
			// The folder itself, or any of its parents.
			if ( $canonical === $dir || str_starts_with( $dir . '/', $canonical . '/' ) ) {
				return 'path_forbidden';
			}
		}

		// Inside code that updates replace or that WordPress executes.
		foreach ( [ WP_PLUGIN_DIR, get_theme_root(), ABSPATH . 'wp-admin', ABSPATH . WPINC, defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '' ] as $dir ) {
			$dir = self::canonical( (string) $dir );
			if ( '' !== $dir && str_starts_with( $canonical . '/', $dir . '/' ) ) {
				return 'path_forbidden';
			}
		}

		if ( is_dir( $path ) && ! self::is_ours( $path ) ) {
			$entries = array_diff( (array) scandir( $path ), [ '.', '..' ] );
			if ( [] !== $entries ) {
				return 'path_not_empty';
			}
		}

		if ( ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) || ! wp_is_writable( $path ) ) {
			return 'path_invalid';
		}

		return '';
	}

	/**
	 * Canonical form of a path whose tail may not exist yet: the nearest
	 * existing ancestor through realpath() (resolving symlinks), plus the
	 * rest.
	 */
	public static function canonical( string $path ): string {
		$path = untrailingslashit( wp_normalize_path( $path ) );
		if ( '' === $path ) {
			return '';
		}

		$tail = [];
		$head = $path;
		while ( '' !== $head && false === realpath( $head ) ) {
			array_unshift( $tail, basename( $head ) );
			$parent = dirname( $head );
			if ( $parent === $head ) {
				break;
			}
			$head = $parent;
		}

		$real = realpath( $head );
		$base = false === $real ? $head : untrailingslashit( wp_normalize_path( $real ) );

		return untrailingslashit( $base . ( [] !== $tail ? '/' . implode( '/', $tail ) : '' ) );
	}

	/**
	 * Drop deny-all files into a directory. Written every time, so a deleted
	 * or edited .htaccess is restored before the next archive is written.
	 */
	public static function protect( string $dir ): void {
		$files = [
			'.htaccess'  => "# Eikvakt Backup Manager: backups must never be served over HTTP.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"
				. "Options -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- Eikvakt Backup Manager: backups must never be served over HTTP. -->\n<configuration>\n\t<system.webServer>\n"
				. "\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n"
				. "\t\t<security>\n\t\t\t<requestFiltering>\n\t\t\t\t<hiddenSegments>\n\t\t\t\t\t<add segment=\".\" />\n\t\t\t\t</hiddenSegments>\n\t\t\t</requestFiltering>\n\t\t</security>\n"
				. "\t</system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Eikvakt Backup Manager: silence is golden.\n",
			'index.html' => "<!-- Eikvakt Backup Manager -->\n",
		];

		// Files of earlier versions, recognised as ours although unsigned.
		$legacy = [ "<?php\n// Silence is golden.\n", '' ];

		foreach ( $files as $name => $content ) {
			$path = $dir . '/' . $name;

			// Never replace a file that is not ours: a foreign .htaccess or
			// index.php in this folder means it is not a dedicated backup
			// directory, and overwriting them could take a site down.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file comparison.
			$current = file_exists( $path ) ? (string) file_get_contents( $path ) : null;
			if ( null !== $current && ! str_contains( $current, self::SIGNATURE ) && ! in_array( $current, $legacy, true ) ) {
				continue;
			}

			if ( $current !== $content ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem is unavailable in cron; a read-only directory is reported by the exposure check.
				@file_put_contents( $path, $content );
			}
		}
	}

	/**
	 * Public URL a file would have if the web server served it, or '' when
	 * the path is not below a directory WordPress knows the URL of.
	 */
	public static function url_for( string $path ): string {
		$path    = wp_normalize_path( $path );
		$content = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$root    = trailingslashit( wp_normalize_path( ABSPATH ) );

		if ( str_starts_with( $path, $content ) ) {
			return content_url( substr( $path, strlen( $content ) ) );
		}

		if ( str_starts_with( $path, $root ) ) {
			return site_url( substr( $path, strlen( $root ) ) );
		}

		return '';
	}

	/**
	 * Request a probe file from the backup directory over HTTP and record
	 * whether the server handed it out.
	 *
	 * Status values: `outside` (not below any web-served directory),
	 * `protected` (the server refused), `exposed` (the server served the probe
	 * — act now), `unknown` (the loopback request itself failed).
	 *
	 * @return array{status:string, checked:int, url:string, detail:string}
	 */
	public static function check_exposure(): array {
		self::ensure();

		$dir    = self::backup_dir();
		$result = [
			'status'  => 'unknown',
			'checked' => time(),
			'url'     => '',
			'detail'  => '',
		];

		$probe_url = self::url_for( $dir . '/' . self::PROBE_PREFIX . 'x.txt' );
		if ( '' === $probe_url || false === self::is_served( $dir ) ) {
			$result['status'] = 'outside';
			update_option( Installer::OPTION_EXPOSURE, $result, false );
			return $result;
		}

		// One probe per file type a backup set actually uses: a web server
		// can treat .zip differently from .txt (MIME rules, download
		// handlers), so a refused .txt alone proves little.
		$outcomes = [];
		foreach ( [ 'zip', 'gz', 'enc', 'json', 'txt' ] as $extension ) {
			$outcomes[ $extension ] = self::probe( $dir, $extension );
			if ( 'exposed' === $outcomes[ $extension ]['status'] ) {
				break;
			}
		}

		$statuses         = array_column( $outcomes, 'status' );
		$result['url']    = (string) ( end( $outcomes )['url'] ?? '' );
		$result['detail'] = implode(
			', ',
			array_map(
				static fn( $ext, $o ) => '.' . $ext . ': ' . $o['detail'],
				array_keys( $outcomes ),
				$outcomes
			)
		);

		if ( in_array( 'exposed', $statuses, true ) ) {
			$result['status'] = 'exposed';
		} elseif ( [ 'protected' ] === array_values( array_unique( $statuses ) ) ) {
			$result['status'] = 'protected';
		} else {
			// Anything that is neither a clear refusal nor the probe itself —
			// an error, a redirect, a 200 with other content — cannot be
			// called protected.
			$result['status'] = 'unknown';
		}

		update_option( Installer::OPTION_EXPOSURE, $result, false );

		return $result;
	}

	/**
	 * Write one probe file, request it, delete it.
	 *
	 * @return array{status:string, url:string, detail:string}
	 */
	private static function probe( string $dir, string $extension ): array {
		$path  = $dir . '/' . self::PROBE_PREFIX . Util::random_token( 8 ) . '.' . $extension;
		$token = 'ekbm-probe-' . Util::random_token( 16 );
		$url   = self::url_for( $path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny probe file, removed below.
		if ( false === file_put_contents( $path, $token ) ) {
			return [
				'status' => 'unknown',
				'url'    => $url,
				'detail' => 'probe-not-writable',
			];
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 10,
				'redirection' => 0,
				// The question is whether the server hands out the file, not
				// who it is: a self-signed local certificate must not turn the
				// check into "unknown". Same convention as core loopbacks.
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter for loopback TLS verification.
				'headers'     => [ 'Cache-Control' => 'no-cache' ],
			]
		);

		wp_delete_file( $path );

		if ( is_wp_error( $response ) ) {
			return [
				'status' => 'unknown',
				'url'    => $url,
				'detail' => $response->get_error_message(),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code && str_contains( (string) wp_remote_retrieve_body( $response ), $token ) ) {
			$status = 'exposed';
		} elseif ( in_array( $code, [ 401, 403, 404, 410 ], true ) ) {
			$status = 'protected';
		} else {
			$status = 'unknown';
		}

		return [
			'status' => $status,
			'url'    => $url,
			'detail' => (string) $code,
		];
	}

	/**
	 * @return array{status:string, checked:int, url:string, detail:string}
	 */
	public static function exposure(): array {
		$stored = (array) get_option( Installer::OPTION_EXPOSURE, [] );

		return [
			'status'  => (string) ( $stored['status'] ?? 'unknown' ),
			'checked' => (int) ( $stored['checked'] ?? 0 ),
			'url'     => (string) ( $stored['url'] ?? '' ),
			'detail'  => (string) ( $stored['detail'] ?? '' ),
		];
	}

	/**
	 * nginx has no per-directory configuration; this is the block an
	 * administrator has to add to the server config instead.
	 */
	public static function nginx_rule(): string {
		$url  = self::url_for( self::backup_dir() . '/x' );
		$path = '' !== $url ? (string) wp_parse_url( dirname( $url ), PHP_URL_PATH ) : '/wp-content/uploads/ekbm-backups-';

		return sprintf( "location ^~ %s/ {\n    deny all;\n    return 403;\n}", untrailingslashit( $path ) );
	}
}
