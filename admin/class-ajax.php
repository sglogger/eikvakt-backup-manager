<?php
/**
 * AJAX endpoints for the admin screens: progress polling (which also drives
 * the job when loopbacks and WP-Cron do not) and chunked upload of backup
 * files from the administrator's computer.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ajax {

	public const NONCE = 'ekbm_ajax';

	/** Per-poll working time: short, so the progress bar stays responsive. */
	private const POLL_BUDGET = 8;

	public function register(): void {
		add_action( 'wp_ajax_ekbm_status', [ $this, 'status' ] );
		add_action( 'wp_ajax_ekbm_upload', [ $this, 'upload' ] );
		add_action( 'wp_ajax_ekbm_tree', [ $this, 'tree' ] );
	}

	private function guard(): void {
		if ( ! current_user_can( Admin::CAP ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! Password_Gate::is_unlocked() ) {
			wp_send_json_error( [ 'message' => 'locked' ], 403 );
		}
	}

	public function status(): void {
		$this->guard();

		if ( Job_Runner::has_work() && ! Job_Store::is_busy() ) {
			Job_Runner::tick( self::POLL_BUDGET );
		}

		wp_send_json_success( Job_Runner::status() );
	}

	/**
	 * Subfolders of one folder for the tree on the Files tab. Only folder
	 * names are returned — never file contents — and only below the two
	 * roots a backup can contain.
	 */
	public function tree(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$path = isset( $_POST['path'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['path'] ) ), '/' ) : '';

		if ( '' === $path ) {
			wp_send_json_success(
				[
					'children' => [
						self::node( 'root', __( 'WordPress directory', 'eikvakt-backup-manager' ) . ' (' . untrailingslashit( wp_normalize_path( ABSPATH ) ) . ')', true ),
						self::node( 'content', 'wp-content (' . untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . ')', true ),
					],
				]
			);
		}

		if ( ! File_Rules::is_valid_path( $path ) ) {
			wp_send_json_error( [ 'message' => 'invalid-path' ], 400 );
		}

		$real    = File_Scanner::real_path( $path . '/' );
		$real    = untrailingslashit( '' === $real ? File_Scanner::root_dir( $path ) : $real );
		$content = File_Scanner::root_dir( 'content' );
		$skip    = File_Scanner::skip_dirs();
		$rules   = Settings::file_rules();

		$names = [];
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable folder simply has no children to show.
		$handle = @opendir( $real );
		if ( false !== $handle ) {
			// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- directory reader.
			while ( false !== ( $name = readdir( $handle ) ) ) {
				$child = $real . '/' . $name;
				if ( '.' === $name || '..' === $name || ! is_dir( $child ) || is_link( $child ) ) {
					continue;
				}
				$normalised = wp_normalize_path( $child );
				// wp-content has its own root node; the backup directory is never backed up.
				if ( ( 'root' === $path && $normalised === $content ) || isset( $skip[ $normalised ] ) ) {
					continue;
				}
				$names[] = $name;
			}
			closedir( $handle );
		}

		natcasesort( $names );

		$children = [];
		foreach ( array_slice( $names, 0, 500 ) as $name ) {
			$child_path = $path . '/' . $name;
			$children[] = array_merge(
				self::node( $child_path, $name, self::has_subdirs( $real . '/' . $name ) ),
				[ 'pattern_excluded' => $rules->pattern_excluded( $child_path . '/' ) ]
			);
		}

		wp_send_json_success(
			[
				'children'  => $children,
				'truncated' => count( $names ) > 500,
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function node( string $path, string $label, bool $has_children ): array {
		return [
			'path'             => $path,
			'label'            => $label,
			'has_children'     => $has_children,
			'pattern_excluded' => false,
		];
	}

	private static function has_subdirs( string $dir ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable means "nothing to expand".
		$handle = @opendir( $dir );
		if ( false === $handle ) {
			return false;
		}
		$found = false;
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- directory reader.
		while ( ! $found && false !== ( $name = readdir( $handle ) ) ) {
			$found = '.' !== $name && '..' !== $name && is_dir( $dir . '/' . $name ) && ! is_link( $dir . '/' . $name );
		}
		closedir( $handle );
		return $found;
	}

	/**
	 * Receive one chunk of a backup file. Files are assembled in the work
	 * directory and only moved into the backup directory once complete and
	 * named like a set file; a finished manifest triggers a catalog rescan.
	 */
	public function upload(): void {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$total  = isset( $_POST['total'] ) ? max( 0, (int) $_POST['total'] ) : 0;
		// phpcs:enable

		$id = (string) strtok( $name, '.' );
		if ( ! Util::is_safe_filename( $name ) || ! Manifest::is_valid_id( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'This is not a file of an Eikvakt backup set.', 'eikvakt-backup-manager' ) ], 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); a binary upload, checked by size below.
		$tmp = isset( $_FILES['chunk']['tmp_name'] ) ? (string) $_FILES['chunk']['tmp_name'] : '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			wp_send_json_error( [ 'message' => __( 'The upload did not arrive.', 'eikvakt-backup-manager' ) ], 400 );
		}

		Paths::ensure();
		$dir = Paths::work_dir() . '/upload';
		wp_mkdir_p( $dir );
		Paths::protect( $dir );

		$part    = $dir . '/' . $name . '.part';
		$current = is_file( $part ) ? (int) filesize( $part ) : 0;

		if ( 0 === $offset ) {
			$current = 0;
			wp_delete_file( $part );
		}
		if ( $offset !== $current ) {
			wp_send_json_error( [ 'message' => 'offset-mismatch' ], 409 );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- appending an uploaded chunk inside the protected work directory.
		$in  = fopen( $tmp, 'rb' );
		$out = fopen( $part, 'ab' );
		Util::pipe( $in, $out );
		fclose( $in );
		fclose( $out );
		// phpcs:enable

		clearstatcache( true, $part );
		$size = (int) filesize( $part );

		if ( $total > 0 && $size >= $total ) {
			rename( $part, Paths::backup_dir() . '/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- completing an upload.
			if ( str_ends_with( $name, '.manifest.json' ) ) {
				Catalog::rescan_local();
			}
		}

		wp_send_json_success( [ 'size' => $size ] );
	}
}
