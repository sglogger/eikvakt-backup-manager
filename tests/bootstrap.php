<?php
/**
 * PHPUnit bootstrap.
 *
 * These are deliberately NOT WordPress integration tests. The classes under
 * test — component classification, manifests, the file index diff, the
 * encryption stream format, serialisation-safe search/replace, schedules,
 * retention and the SQL table rewriting — were written to be free of
 * WordPress so they can be tested as plain PHP. End-to-end behaviour
 * (backup, restore, migration against real servers) is exercised in the
 * Docker environment; see docs/testing.md.
 *
 * Plugin class files all start with an `ABSPATH` guard that exits when the
 * file is loaded outside WordPress, so the constant is defined first, along
 * with the handful of WordPress functions the tested code touches.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
