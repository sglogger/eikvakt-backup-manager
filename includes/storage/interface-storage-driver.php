<?php
/**
 * A place backup sets can be copied to.
 *
 * Drivers are flat: every set file is stored under its own name in the
 * destination's folder. Transfers are resumable — `upload()` and `download()`
 * take and return an opaque state array and stop at the deadline, so a
 * multi-gigabyte part is moved across as many requests as it needs.
 *
 * Errors are thrown as \RuntimeException with a message meant for the
 * administrator; the job engine retries and then reports them.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Storage_Driver {

	/**
	 * Check that the destination is reachable and writable.
	 *
	 * @return true|\WP_Error
	 */
	public function test();

	/**
	 * Copy a local file to the destination, continuing from `$state`.
	 *
	 * @param array<string, mixed> $state Driver state; [] on the first call.
	 * @return array<string, mixed> New state; `done` => true when complete.
	 */
	public function upload( string $local, string $name, array $state, float $deadline ): array;

	/**
	 * Copy a file from the destination to a local path, continuing from `$state`.
	 *
	 * @param array<string, mixed> $state Driver state; [] on the first call.
	 * @return array<string, mixed> New state; `done` => true when complete.
	 */
	public function download( string $name, string $local, array $state, float $deadline ): array;

	/**
	 * Names of the files in the destination folder.
	 *
	 * @return string[]
	 */
	public function list_names(): array;

	/**
	 * Contents of a small file (a manifest).
	 */
	public function get_contents( string $name ): string;

	public function delete( string $name ): bool;
}
