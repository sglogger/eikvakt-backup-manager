<?php
/**
 * A kind of job the runner can execute in resumable steps.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Job_Handler {

	/**
	 * Prepare the job's state. Called once, before the first step.
	 *
	 * @param array<string, mixed> $job Job with `params` filled in.
	 * @return array<string, mixed> The job with `state` initialised.
	 */
	public function start( array $job ): array;

	/**
	 * Do as much work as fits before the deadline. Must leave the job in a
	 * state from which the next step can continue, and set `status` to
	 * `done` or `failed` when finished.
	 *
	 * @param array<string, mixed> $job      Job.
	 * @param float                $deadline microtime(true) to stop at.
	 * @return array<string, mixed>
	 */
	public function step( array $job, float $deadline ): array;

	/**
	 * Remove temporary files once the job has ended, whatever the outcome.
	 *
	 * @param array<string, mixed> $job Job.
	 */
	public function cleanup( array $job ): void;
}
