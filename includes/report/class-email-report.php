<?php
/**
 * E-mail report after a job: always, only on problems, or never.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Email_Report {

	/**
	 * @return string[]
	 */
	public static function recipients(): array {
		$raw = (string) ( Settings::general()['email_recipients'] ?? '' );
		$out = [];
		foreach ( preg_split( '/[\s,;]+/', $raw ) ?: [] as $email ) {
			$email = sanitize_email( $email );
			if ( '' !== $email && is_email( $email ) ) {
				$out[] = $email;
			}
		}
		if ( [] === $out ) {
			$out[] = (string) get_option( 'admin_email' );
		}
		return array_values( array_unique( $out ) );
	}

	public static function maybe_send( int $run_id ): void {
		$mode = (string) ( Settings::general()['email_mode'] ?? 'failures' );
		$run  = Run_Repository::get( $run_id );

		if ( null === $run || 'never' === $mode ) {
			return;
		}

		$problem = in_array( $run['status'], [ Run_Repository::STATUS_FAILED, Run_Repository::STATUS_WARNING ], true );
		if ( 'failures' === $mode && ! $problem ) {
			return;
		}

		self::send( $run );
	}

	/**
	 * @param array<string, mixed> $run Run row.
	 */
	public static function send( array $run ): bool {
		$site   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$status = Run_Repository::status_label( (string) $run['status'] );

		$subject = sprintf(
			/* translators: 1: site name, 2: kind of run (Backup/Restore), 3: status */
			__( '[%1$s] %2$s: %3$s', 'eikvakt-backup-manager' ),
			$site,
			Run_Repository::kind_label( (string) $run['kind'] ),
			$status
		);

		$lines   = [];
		$lines[] = sprintf( '%s: %s', __( 'Site', 'eikvakt-backup-manager' ), home_url() );
		$lines[] = sprintf( '%s: %s', __( 'Status', 'eikvakt-backup-manager' ), $status );
		$lines[] = sprintf( '%s: %s', __( 'Trigger', 'eikvakt-backup-manager' ), Run_Repository::trigger_label( (string) $run['run_trigger'] ) );
		$lines[] = sprintf( '%s: %s', __( 'Started', 'eikvakt-backup-manager' ), Util::datetime( (int) strtotime( $run['started_at'] . ' UTC' ) ) );

		if ( '' !== (string) $run['backup_id'] ) {
			$lines[] = sprintf( '%s: %s', __( 'Backup', 'eikvakt-backup-manager' ), $run['backup_id'] );
		}
		if ( '' !== (string) $run['components'] ) {
			$lines[] = sprintf(
				'%s: %s',
				__( 'Included', 'eikvakt-backup-manager' ),
				implode( ', ', array_map( [ Components::class, 'short_label' ], explode( ',', (string) $run['components'] ) ) )
			);
		}
		if ( (int) $run['size_bytes'] > 0 ) {
			$lines[] = sprintf( '%s: %s', __( 'Size', 'eikvakt-backup-manager' ), Util::bytes( (int) $run['size_bytes'] ) );
		}

		$destinations = json_decode( (string) $run['destinations'], true );
		if ( is_array( $destinations ) && [] !== $destinations ) {
			$parts = [];
			foreach ( $destinations as $id => $outcome ) {
				$parts[] = Destinations::name( (string) $id ) . ' (' . ( 'done' === $outcome ? __( 'OK', 'eikvakt-backup-manager' ) : __( 'failed', 'eikvakt-backup-manager' ) ) . ')';
			}
			$lines[] = sprintf( '%s: %s', __( 'Stored on', 'eikvakt-backup-manager' ), implode( ', ', $parts ) );
		}

		if ( '' !== trim( (string) $run['summary'] ) ) {
			$lines[] = '';
			$lines[] = (string) $run['summary'];
		}

		$lines[] = '';
		$lines[] = __( 'Full report:', 'eikvakt-backup-manager' ) . ' ' . add_query_arg(
			[
				'page' => Admin::MENU_REPORTS,
				'run'  => (int) $run['id'],
			],
			admin_url( 'admin.php' )
		);

		return wp_mail( self::recipients(), $subject, implode( "\n", $lines ) );
	}
}
