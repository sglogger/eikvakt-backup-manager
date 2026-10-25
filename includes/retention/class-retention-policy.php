<?php
/**
 * Which backups to delete.
 *
 * Rules, applied per kind of backup:
 *
 *   chains (full + its incrementals)
 *     - keep the newest `keep_chains` chains, or, with GFS enabled, the
 *       newest chain of each of the last `gfs_daily` days, `gfs_weekly`
 *       ISO weeks and `gfs_monthly` months;
 *     - a chain is only ever deleted as a whole — deleting a full backup
 *       would make every incremental built on it worthless, deleting an
 *       incremental in the middle would break the ones after it;
 *   database-only backups: keep the newest `keep_database`;
 *   partial backups (manual selections, before updates): keep the newest
 *     `keep_partial`;
 *   all kinds: delete anything older than `max_age_days` (0 = no limit).
 *
 * The newest complete backup of each kind is always kept, whatever the
 * rules say: a retention setting must never leave a site with no backup.
 *
 * Backups an administrator marked "keep" are taken out before any rule is
 * applied: they are never deleted and do not count towards the limits. A
 * marked incremental needs its full backup and every incremental before it,
 * so marking any member protects its whole chain.
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

final class Retention_Policy {

	/**
	 * @param Manifest[]           $sets  Known sets.
	 * @param array<string, mixed> $rules Retention settings.
	 * @return string[] Ids to delete.
	 */
	public static function to_delete( array $sets, array $rules, int $now, \DateTimeZone $tz ): array {
		$sets     = self::unprotected( $sets );
		$chains   = [];
		$database = [];
		$partial  = [];

		foreach ( $sets as $set ) {
			switch ( $set->type() ) {
				case Manifest::TYPE_FULL:
				case Manifest::TYPE_INCREMENTAL:
					$head              = '' !== $set->chain() ? $set->chain() : $set->id();
					$chains[ $head ][] = $set;
					break;
				case Manifest::TYPE_DATABASE:
					$database[] = $set;
					break;
				default:
					$partial[] = $set;
			}
		}

		$max_age = max( 0, (int) ( $rules['max_age_days'] ?? 0 ) ) * 86400;
		$delete  = [];

		// --- Chains -----------------------------------------------------------
		// Sort chains newest first by the time of their newest member.
		$newest = [];
		foreach ( $chains as $head => $members ) {
			$newest[ $head ] = max( array_map( static fn( Manifest $m ) => $m->created(), $members ) );
		}
		arsort( $newest );
		$order = array_keys( $newest );

		$keep = [];
		if ( ! empty( $rules['gfs_enabled'] ) ) {
			$keep = self::gfs( $newest, $rules, $now, $tz );
		} else {
			$keep = array_slice( $order, 0, max( 1, (int) ( $rules['keep_chains'] ?? 4 ) ) );
		}
		if ( [] !== $order ) {
			$keep[] = $order[0];
		}

		foreach ( $order as $i => $head ) {
			$expired = $max_age > 0 && $newest[ $head ] < $now - $max_age && 0 !== $i;
			if ( ! in_array( $head, $keep, true ) || $expired ) {
				foreach ( $chains[ $head ] as $member ) {
					$delete[] = $member->id();
				}
			}
		}

		// --- Standalone kinds -------------------------------------------------
		$delete = array_merge(
			$delete,
			self::newest_n( $database, max( 1, (int) ( $rules['keep_database'] ?? 14 ) ), $max_age, $now ),
			self::newest_n( $partial, max( 1, (int) ( $rules['keep_partial'] ?? 10 ) ), $max_age, $now )
		);

		return array_values( array_unique( $delete ) );
	}

	/**
	 * @param Manifest[] $sets Sets of one kind.
	 * @return string[] Ids beyond the newest N (and expired ones).
	 */
	private static function newest_n( array $sets, int $keep, int $max_age, int $now ): array {
		usort( $sets, static fn( Manifest $a, Manifest $b ) => $b->created() <=> $a->created() );

		$delete = [];
		foreach ( $sets as $i => $set ) {
			if ( 0 === $i ) {
				continue;
			}
			if ( $i >= $keep || ( $max_age > 0 && $set->created() < $now - $max_age ) ) {
				$delete[] = $set->id();
			}
		}
		return $delete;
	}

	/**
	 * Grandfather-father-son: the newest chain per day, per week and per
	 * month, for the configured number of each.
	 *
	 * @param array<string, int>   $newest Chain head => newest member time, newest first.
	 * @param array<string, mixed> $rules  Rules.
	 * @return string[] Chain heads to keep.
	 */
	private static function gfs( array $newest, array $rules, int $now, \DateTimeZone $tz ): array {
		$limits = [
			'Y-m-d' => max( 0, (int) ( $rules['gfs_daily'] ?? 7 ) ),
			'o-W'   => max( 0, (int) ( $rules['gfs_weekly'] ?? 4 ) ),
			'Y-m'   => max( 0, (int) ( $rules['gfs_monthly'] ?? 6 ) ),
		];

		$keep = [];
		foreach ( $limits as $format => $count ) {
			$buckets = [];
			foreach ( $newest as $head => $time ) {
				$bucket = ( new \DateTimeImmutable( '@' . $time ) )->setTimezone( $tz )->format( $format );
				if ( isset( $buckets[ $bucket ] ) ) {
					continue;
				}
				if ( count( $buckets ) >= $count ) {
					break;
				}
				$buckets[ $bucket ] = $head;
			}
			$keep = array_merge( $keep, array_values( $buckets ) );
		}

		return array_values( array_unique( $keep ) );
	}

	/**
	 * Copies older than the retention period of their location.
	 *
	 * Each location (this server, every remote destination) can keep backups
	 * for a different number of days — for example 7 days on this server and
	 * 90 days in the cloud. Age is measured per chain by its newest member,
	 * and a chain is removed from a location as a whole. The newest chain
	 * and the newest database backup are never removed by age.
	 *
	 * @param Manifest[]              $sets      Sets of this site.
	 * @param array<string, string[]> $locations Set id => locations.
	 * @param array<string, int>      $days      Location id => days (0 = unlimited).
	 * @return array<int, array{0:string, 1:string}> [set id, location] pairs.
	 */
	public static function expired_copies( array $sets, array $locations, array $days, int $now ): array {
		$sets       = self::unprotected( $sets );
		$chain_time = [];
		$newest     = [];

		foreach ( $sets as $set ) {
			$group                = self::group( $set );
			$chain_time[ $group ] = max( $chain_time[ $group ] ?? 0, $set->created() );

			$kind = Manifest::TYPE_INCREMENTAL === $set->type() ? Manifest::TYPE_FULL : $set->type();
			if ( ! isset( $newest[ $kind ] ) || $chain_time[ $group ] > $newest[ $kind ][1] ) {
				$newest[ $kind ] = [ $group, $chain_time[ $group ] ];
			}
		}

		$protected = array_column( $newest, 0 );
		$out       = [];

		foreach ( $sets as $set ) {
			$group = self::group( $set );
			if ( in_array( $group, $protected, true ) ) {
				continue;
			}

			foreach ( $locations[ $set->id() ] ?? [] as $location ) {
				$limit = (int) ( $days[ $location ] ?? 0 );
				if ( $limit > 0 && $chain_time[ $group ] < $now - $limit * 86400 ) {
					$out[] = [ $set->id(), $location ];
				}
			}
		}

		return $out;
	}

	/**
	 * Sets that retention may touch: everything except kept sets and the
	 * chains they belong to.
	 *
	 * @param Manifest[] $sets Sets.
	 * @return Manifest[]
	 */
	public static function unprotected( array $sets ): array {
		$protected = [];
		foreach ( $sets as $set ) {
			if ( $set->is_kept() ) {
				$protected[ self::group( $set ) ] = true;
			}
		}

		return array_values( array_filter( $sets, static fn( Manifest $set ) => ! isset( $protected[ self::group( $set ) ] ) ) );
	}

	/**
	 * Chain head for chain members, the set itself otherwise.
	 */
	private static function group( Manifest $set ): string {
		if ( in_array( $set->type(), [ Manifest::TYPE_FULL, Manifest::TYPE_INCREMENTAL ], true ) && '' !== $set->chain() ) {
			return $set->chain();
		}
		return $set->id();
	}

	/**
	 * Which sets to drop from this server only (copies remain elsewhere).
	 * `local_chains` / `local_database` of 0 mean "same as everywhere".
	 *
	 * @param Manifest[]                 $sets      Sets with a local copy.
	 * @param array<string, string[]>    $locations Set id => locations.
	 * @param array<string, mixed>       $rules     Rules.
	 * @return string[]
	 */
	public static function local_to_delete( array $sets, array $locations, array $rules ): array {
		$sets   = self::unprotected( $sets );
		$limits = [
			'chains'   => (int) ( $rules['local_chains'] ?? 0 ),
			'database' => (int) ( $rules['local_database'] ?? 0 ),
		];

		$heads = [];
		$dbs   = [];
		foreach ( $sets as $set ) {
			if ( in_array( $set->type(), [ Manifest::TYPE_FULL, Manifest::TYPE_INCREMENTAL ], true ) ) {
				$head           = '' !== $set->chain() ? $set->chain() : $set->id();
				$heads[ $head ] = max( $heads[ $head ] ?? 0, $set->created() );
			} elseif ( Manifest::TYPE_DATABASE === $set->type() ) {
				$dbs[ $set->id() ] = $set->created();
			}
		}
		arsort( $heads );
		arsort( $dbs );

		$drop_heads = $limits['chains'] > 0 ? array_slice( array_keys( $heads ), $limits['chains'] ) : [];
		$drop_dbs   = $limits['database'] > 0 ? array_slice( array_keys( $dbs ), $limits['database'] ) : [];

		$delete = [];
		foreach ( $sets as $set ) {
			$head   = '' !== $set->chain() ? $set->chain() : $set->id();
			$remote = array_diff( $locations[ $set->id() ] ?? [], [ 'local' ] );
			if ( [] === $remote ) {
				continue;
			}
			if ( in_array( $head, $drop_heads, true ) || in_array( $set->id(), $drop_dbs, true ) ) {
				$delete[] = $set->id();
			}
		}

		return $delete;
	}
}
