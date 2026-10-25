<?php
/**
 * Plugin Name: Dev — route mail to Mailpit
 * Description: Local development only. Points PHPMailer at the Mailpit container so report e-mails can be inspected instead of silently failing to send.
 *
 * This file lives OUTSIDE the plugin directory (dev/mu-plugins/, mounted only
 * by docker-compose) and is excluded from the release ZIP. It must never ship.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'phpmailer_init',
	static function ( $phpmailer ): void {
		// Belt and braces: only ever active in a local environment.
		if ( 'local' !== wp_get_environment_type() ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host       = 'mailpit'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's API.
		$phpmailer->Port       = 1025;      // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's API.
		$phpmailer->SMTPAuth   = false;     // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's API.
		$phpmailer->SMTPSecure = '';        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's API.
	}
);
