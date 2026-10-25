<?php
/**
 * Import a backup from another site with a migration key.
 *
 * Downloads the set (and, for an incremental, its chain) in chunks into the
 * protected backup directory and adds it to the catalog. Restoring it is a
 * separate, deliberate step on the Restore screen.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Pull_Job implements Job_Handler {

	public function start( array $job ): array {
		$key = Migration::parse_key( Secret_Cipher::decrypt( (string) ( $job['params']['key'] ?? '' ) ) );
		if ( null === $key ) {
			throw new \RuntimeException( 'The migration key is not valid.' );
		}

		$allow_private = ! empty( $job['params']['allow_private'] );
		self::check_transport( $key, $allow_private );

		$response = self::call( $key, [ 'op' => 'list' ], $allow_private );
		$data     = json_decode( $response['body'], true );

		if ( 200 !== $response['code'] || empty( $data['success'] ) ) {
			throw new \RuntimeException( 'The source site refused the migration key (expired or revoked?).' );
		}

		$files     = [];
		$manifests = [];
		foreach ( (array) $data['data']['manifests'] as $raw ) {
			$manifest = new Manifest( (array) $raw );
			if ( ! Manifest::is_valid_id( $manifest->id() ) ) {
				throw new \RuntimeException( 'The source site sent an invalid manifest.' );
			}
			$manifests[] = $manifest->data;
			foreach ( $manifest->files() as $file ) {
				if ( ! Util::is_safe_filename( $file['name'] ) ) {
					throw new \RuntimeException( 'The source site sent an invalid file name.' );
				}
				$files[] = $file;
			}
		}

		$job['state'] = [
			'manifests' => $manifests,
			'files'     => $files,
			'file'      => 0,
			'offset'    => 0,
			'bytes'     => 0,
			'total'     => (int) array_sum( array_column( $files, 'size' ) ),
		];
		$job['phase'] = 'download';

		return Job_Runner::log( $job, sprintf( 'Importing backup %s from %s (%s).', $key['b'], (string) wp_parse_url( $key['u'], PHP_URL_HOST ), Util::bytes( $job['state']['total'] ) ) );
	}

	/**
	 * @param array{u:string, t:string, b:string} $key  Parsed key.
	 * @param array<string, mixed>                $body Request body.
	 * @return array{code:int, body:string, headers:array<string, string>}
	 */
	private static function call( array $key, array $body, bool $allow_private ): array {
		return OAuth::http(
			add_query_arg( 'action', 'ekbm_migrate', $key['u'] ),
			[
				'method'             => 'POST',
				'timeout'            => 120,
				'body'               => array_merge( [ 'token' => $key['t'] ], $body ),
				// A migration key comes from outside. Unless the administrator
				// said the source is on a private network, refuse loopback,
				// private and link-local targets (cloud metadata) and
				// non-standard ports — and never follow a redirect elsewhere.
				'reject_unsafe_urls' => ! $allow_private,
				'redirection'        => 0,
			]
		);
	}

	/**
	 * Refuse plain http for a source on the internet.
	 *
	 * @param array{u:string, t:string, b:string} $key Parsed key.
	 */
	private static function check_transport( array $key, bool $allow_private ): void {
		if ( ! $allow_private && 'https' !== wp_parse_url( $key['u'], PHP_URL_SCHEME ) ) {
			throw new \RuntimeException( 'The source site is not reached over HTTPS. Use an HTTPS address on the source, or confirm that the source is on your private network.' );
		}
	}

	public function step( array $job, float $deadline ): array {
		$key   = Migration::parse_key( Secret_Cipher::decrypt( (string) ( $job['params']['key'] ?? '' ) ) );
		$state = (array) $job['state'];
		$files = (array) $state['files'];
		$dir   = Paths::backup_dir();

		$count = count( $files );

		while ( (int) $state['file'] < $count && microtime( true ) < $deadline ) {
			$file = $files[ (int) $state['file'] ];
			$part = $dir . '/' . $file['name'] . '.part';

			$response = self::call(
				$key,
				[
					'op'     => 'chunk',
					'name'   => $file['name'],
					'offset' => (int) $state['offset'],
				],
				! empty( $job['params']['allow_private'] )
			);

			if ( 200 !== $response['code'] ) {
				throw new \RuntimeException( esc_html( sprintf( 'The source site refused %s (HTTP %d).', $file['name'], $response['code'] ) ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a downloaded chunk into the protected backup directory.
			file_put_contents( $part, $response['body'], 0 === (int) $state['offset'] ? 0 : FILE_APPEND );

			$state['offset'] += strlen( $response['body'] );
			$state['bytes']  += strlen( $response['body'] );

			if ( (int) $state['offset'] >= (int) $file['size'] || '' === $response['body'] ) {
				if ( (int) filesize( $part ) !== (int) $file['size'] ) {
					throw new \RuntimeException( esc_html( sprintf( 'Size mismatch for %s.', $file['name'] ) ) );
				}
				rename( $part, $dir . '/' . $file['name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- completing a download.
				++$state['file'];
				$state['offset'] = 0;
			}
		}

		$job['message'] = sprintf(
			/* translators: 1: bytes downloaded, 2: total bytes */
			__( 'Downloading from the source site: %1$s of %2$s', 'eikvakt-backup-manager' ),
			Util::bytes( (int) $state['bytes'] ),
			Util::bytes( (int) $state['total'] )
		);
		$job['progress'] = (int) min( 99, 100 * (int) $state['bytes'] / max( 1, (int) $state['total'] ) );

		if ( (int) $state['file'] >= count( $files ) ) {
			$last = null;
			foreach ( (array) $state['manifests'] as $raw ) {
				$manifest = new Manifest( (array) $raw );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- manifest inside the protected backup directory.
				file_put_contents( $dir . '/' . Manifest::manifest_name( $manifest->id() ), $manifest->to_json() );
				Catalog::put( $manifest, [ Storage_Local::ID ] );
				$last = $manifest;
			}

			$job['status']   = Job_Runner::STATUS_DONE;
			$job['progress'] = 100;
			$job['message']  = __( 'Import complete. The backup can now be restored.', 'eikvakt-backup-manager' );
			$job['result']   = [
				'backup_id'   => null !== $last ? $last->id() : '',
				'backup_type' => null !== $last ? $last->type() : '',
				'components'  => null !== $last ? $last->components() : [],
				'size'        => (int) $state['total'],
			];
			$job             = Job_Runner::log( $job, 'Import complete.' );
		}

		$job['state'] = $state;

		return $job;
	}

	public function cleanup( array $job ): void {
		if ( Job_Runner::STATUS_DONE === $job['status'] ) {
			return;
		}
		foreach ( (array) ( $job['state']['files'] ?? [] ) as $file ) {
			if ( Util::is_safe_filename( (string) $file['name'] ) ) {
				wp_delete_file( Paths::backup_dir() . '/' . $file['name'] . '.part' );
			}
		}
	}
}
