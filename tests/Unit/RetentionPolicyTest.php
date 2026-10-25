<?php
declare( strict_types = 1 );

namespace EikvaktBackupManager\Tests\Unit;

use EikvaktBackupManager\Manifest;
use EikvaktBackupManager\Retention_Policy;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase {

	private const DAY = 86400;

	private int $now;

	protected function setUp(): void {
		$this->now = 1792413600;
	}

	private function set( string $id, string $type, int $days_ago, string $chain = '' ): Manifest {
		return new Manifest(
			[
				'id'       => $id,
				'type'     => $type,
				'chain'    => $chain,
				'created'  => $this->now - $days_ago * self::DAY,
				'complete' => true,
			]
		);
	}

	/**
	 * Three chains of a full plus incrementals, newest first.
	 *
	 * @return Manifest[]
	 */
	private function chains(): array {
		return [
			$this->set( 'c3i', Manifest::TYPE_INCREMENTAL, 0, 'c3' ),
			$this->set( 'c3', Manifest::TYPE_FULL, 1, 'c3' ),
			$this->set( 'c2i', Manifest::TYPE_INCREMENTAL, 7, 'c2' ),
			$this->set( 'c2', Manifest::TYPE_FULL, 8, 'c2' ),
			$this->set( 'c1i', Manifest::TYPE_INCREMENTAL, 14, 'c1' ),
			$this->set( 'c1', Manifest::TYPE_FULL, 15, 'c1' ),
		];
	}

	public function test_whole_chains_are_deleted_never_parts(): void {
		$delete = Retention_Policy::to_delete( $this->chains(), [ 'keep_chains' => 2 ], $this->now, new \DateTimeZone( 'UTC' ) );
		sort( $delete );
		$this->assertSame( [ 'c1', 'c1i' ], $delete );
	}

	public function test_newest_chain_survives_any_rule(): void {
		$delete = Retention_Policy::to_delete(
			$this->chains(),
			[
				'keep_chains'  => 1,
				'max_age_days' => 0.5,
			],
			$this->now,
			new \DateTimeZone( 'UTC' )
		);
		$this->assertNotContains( 'c3', $delete );
		$this->assertNotContains( 'c3i', $delete );
	}

	public function test_max_age(): void {
		$delete = Retention_Policy::to_delete(
			$this->chains(),
			[
				'keep_chains'  => 10,
				'max_age_days' => 10,
			],
			$this->now,
			new \DateTimeZone( 'UTC' )
		);
		sort( $delete );
		$this->assertSame( [ 'c1', 'c1i' ], $delete );
	}

	public function test_standalone_kinds_keep_newest_n(): void {
		$sets = [
			$this->set( 'd1', Manifest::TYPE_DATABASE, 0 ),
			$this->set( 'd2', Manifest::TYPE_DATABASE, 1 ),
			$this->set( 'd3', Manifest::TYPE_DATABASE, 2 ),
			$this->set( 'p1', Manifest::TYPE_PARTIAL, 3 ),
		];
		$this->assertSame(
			[ 'd3' ],
			Retention_Policy::to_delete(
				$sets,
				[
					'keep_database' => 2,
					'keep_partial'  => 1,
				],
				$this->now,
				new \DateTimeZone( 'UTC' )
			)
		);
	}

	public function test_expired_copies_per_location(): void {
		$sets      = $this->chains();
		$locations = [];
		foreach ( $sets as $set ) {
			$locations[ $set->id() ] = [ 'local', 'dropbox' ];
		}

		// 5 days on this server, 10 days in the cloud.
		$expired = Retention_Policy::expired_copies(
			$sets,
			$locations,
			[
				'local'   => 5,
				'dropbox' => 10,
			],
			$this->now
		);

		$pairs = array_map( static fn( $p ) => $p[0] . '@' . $p[1], $expired );
		sort( $pairs );

		$this->assertSame( [ 'c1@dropbox', 'c1@local', 'c1i@dropbox', 'c1i@local', 'c2@local', 'c2i@local' ], $pairs );
	}

	/**
	 * @param Manifest[] $sets Sets.
	 * @param string[]   $ids  Ids to mark.
	 * @return Manifest[]
	 */
	private function mark( array $sets, array $ids ): array {
		foreach ( $sets as $set ) {
			if ( in_array( $set->id(), $ids, true ) ) {
				$set->data['keep'] = true;
			}
		}
		return $sets;
	}

	public function test_kept_chain_survives_and_does_not_count(): void {
		// Oldest chain kept by marking its incremental; limit of one chain.
		$sets   = $this->mark( $this->chains(), [ 'c1i' ] );
		$delete = Retention_Policy::to_delete(
			$sets,
			[
				'keep_chains'  => 1,
				'max_age_days' => 5,
			],
			$this->now,
			new \DateTimeZone( 'UTC' )
		);
		sort( $delete );

		// c1 + c1i protected as a whole; c3 is the one counted chain; c2 goes.
		$this->assertSame( [ 'c2', 'c2i' ], $delete );
	}

	public function test_kept_standalone_backups_are_exempt(): void {
		$sets = $this->mark(
			[
				$this->set( 'd1', Manifest::TYPE_DATABASE, 0 ),
				$this->set( 'd2', Manifest::TYPE_DATABASE, 1 ),
				$this->set( 'd3', Manifest::TYPE_DATABASE, 400 ),
			],
			[ 'd3' ]
		);

		$this->assertSame(
			[ 'd2' ],
			Retention_Policy::to_delete(
				$sets,
				[
					'keep_database' => 1,
					'max_age_days'  => 30,
				],
				$this->now,
				new \DateTimeZone( 'UTC' )
			)
		);
	}

	public function test_kept_backups_are_exempt_from_location_days_and_local_thinning(): void {
		$sets      = $this->mark( $this->chains(), [ 'c1' ] );
		$locations = [];
		foreach ( $sets as $set ) {
			$locations[ $set->id() ] = [ 'local', 'dropbox' ];
		}

		$expired = Retention_Policy::expired_copies(
			$sets,
			$locations,
			[
				'local'   => 1,
				'dropbox' => 1,
			],
			$this->now
		);
		$ids     = array_unique( array_column( $expired, 0 ) );
		sort( $ids );
		$this->assertSame( [ 'c2', 'c2i' ], $ids );

		$local = Retention_Policy::local_to_delete( $sets, $locations, [ 'local_chains' => 1 ] );
		sort( $local );
		$this->assertSame( [ 'c2', 'c2i' ], $local );
	}

	public function test_expired_copies_never_touch_the_newest_backup(): void {
		$sets = [ $this->set( 'old', Manifest::TYPE_DATABASE, 100 ) ];
		$this->assertSame( [], Retention_Policy::expired_copies( $sets, [ 'old' => [ 'local' ] ], [ 'local' => 1 ], $this->now ) );
	}
}
