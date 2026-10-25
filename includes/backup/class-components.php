<?php
/**
 * The parts of a site that can be backed up and restored separately.
 *
 * Every file in an archive is stored under one of two logical roots:
 *
 *   root/<path>     relative to ABSPATH (WordPress core, wp-config.php, any
 *                   other file in the installation directory)
 *   content/<path>  relative to WP_CONTENT_DIR
 *
 * Two roots rather than one so a restore onto a host whose wp-content lives
 * somewhere else (a common layout on managed hosting) still puts every file
 * where WordPress expects it. The component a file belongs to is derived from
 * its archive path alone — no lookups — which keeps classification identical
 * on the source and the target of a migration.
 *
 * Free of WordPress: unit-tested on its own.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Components {

	public const CORE     = 'core';
	public const PLUGINS  = 'plugins';
	public const THEMES   = 'themes';
	public const UPLOADS  = 'uploads';
	public const CONTENT  = 'content';
	public const DATABASE = 'database';

	/** Every file component, in display order. */
	public const FILE_COMPONENTS = [ self::CORE, self::PLUGINS, self::THEMES, self::UPLOADS, self::CONTENT ];

	/** Everything, in display order. */
	public const ALL = [ self::DATABASE, self::CORE, self::PLUGINS, self::THEMES, self::UPLOADS, self::CONTENT ];

	/**
	 * Component of an archive path.
	 */
	public static function classify( string $archive_path ): string {
		if ( str_starts_with( $archive_path, 'content/' ) ) {
			$rest = substr( $archive_path, 8 );

			if ( str_starts_with( $rest, 'plugins/' ) || str_starts_with( $rest, 'mu-plugins/' ) ) {
				return self::PLUGINS;
			}
			if ( str_starts_with( $rest, 'themes/' ) ) {
				return self::THEMES;
			}
			if ( str_starts_with( $rest, 'uploads/' ) ) {
				return self::UPLOADS;
			}
			return self::CONTENT;
		}

		return self::CORE;
	}

	/**
	 * Keep only known component names, in canonical order.
	 *
	 * @param mixed $components Untrusted list.
	 * @return string[]
	 */
	public static function sanitize( $components, bool $allow_database = true ): array {
		$components = array_map( 'strval', (array) $components );
		$allowed    = $allow_database ? self::ALL : self::FILE_COMPONENTS;

		return array_values( array_intersect( $allowed, $components ) );
	}

	/**
	 * @param string[] $components Components.
	 * @return string[]
	 */
	public static function file_components( array $components ): array {
		return array_values( array_intersect( self::FILE_COMPONENTS, $components ) );
	}

	/**
	 * Translated label.
	 */
	public static function label( string $component ): string {
		switch ( $component ) {
			case self::CORE:
				return __( 'WordPress core & root files', 'eikvakt-backup-manager' );
			case self::PLUGINS:
				return __( 'Plugins', 'eikvakt-backup-manager' );
			case self::THEMES:
				return __( 'Themes', 'eikvakt-backup-manager' );
			case self::UPLOADS:
				return __( 'Uploads (media)', 'eikvakt-backup-manager' );
			case self::CONTENT:
				return __( 'Other wp-content files', 'eikvakt-backup-manager' );
			case self::DATABASE:
				return __( 'Database', 'eikvakt-backup-manager' );
		}
		return $component;
	}

	/**
	 * Short label for tables and badges.
	 */
	public static function short_label( string $component ): string {
		switch ( $component ) {
			case self::CORE:
				return __( 'Core', 'eikvakt-backup-manager' );
			case self::CONTENT:
				return __( 'wp-content', 'eikvakt-backup-manager' );
			case self::UPLOADS:
				return __( 'Uploads', 'eikvakt-backup-manager' );
		}
		return self::label( $component );
	}
}
