<?php
/**
 * When is a schedule next due?
 *
 * Frequencies:
 *   off       never
 *   interval  every N hours after the previous run
 *   daily     every day at HH:MM
 *   weekly    on one weekday (1 = Monday … 7 = Sunday) at HH:MM
 *   monthly   on one day of the month (1–31, clamped to the month's length)
 *             at HH:MM
 *
 * Times are in the site's timezone, so "02:00" stays 02:00 across daylight
 * saving changes.
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

final class Schedule {

	public const FREQUENCIES = [ 'off', 'interval', 'daily', 'weekly', 'monthly' ];

	/**
	 * Next due time (Unix timestamp), or null when the schedule is off.
	 *
	 * @param array<string, mixed> $config Schedule config.
	 * @param int                  $last   Previous run (0 = never).
	 */
	public static function next_run( array $config, int $last, int $now, \DateTimeZone $tz ): ?int {
		$frequency = (string) ( $config['frequency'] ?? 'off' );

		if ( 'interval' === $frequency ) {
			$hours = max( 1, min( 168, (int) ( $config['interval_hours'] ?? 12 ) ) );
			return $last > 0 ? $last + $hours * 3600 : $now;
		}

		if ( ! in_array( $frequency, [ 'daily', 'weekly', 'monthly' ], true ) ) {
			return null;
		}

		[ $hour, $minute ] = self::parse_time( (string) ( $config['time'] ?? '02:00' ) );

		// The first slot strictly after the previous run; for a schedule that
		// never ran, the first slot from now on.
		$base = new \DateTimeImmutable( '@' . ( $last > 0 ? $last : $now - 1 ) );
		$base = $base->setTimezone( $tz );

		$candidate = $base->setTime( $hour, $minute );

		switch ( $frequency ) {
			case 'daily':
				if ( $candidate <= $base ) {
					$candidate = $candidate->modify( '+1 day' )->setTime( $hour, $minute );
				}
				break;

			case 'weekly':
				$weekday   = max( 1, min( 7, (int) ( $config['weekday'] ?? 1 ) ) );
				$delta     = ( $weekday - (int) $candidate->format( 'N' ) + 7 ) % 7;
				$candidate = $candidate->modify( '+' . $delta . ' days' )->setTime( $hour, $minute );
				if ( $candidate <= $base ) {
					$candidate = $candidate->modify( '+7 days' )->setTime( $hour, $minute );
				}
				break;

			case 'monthly':
				$day       = max( 1, min( 31, (int) ( $config['monthday'] ?? 1 ) ) );
				$candidate = self::month_slot( $base, 0, $day, $hour, $minute );
				if ( $candidate <= $base ) {
					$candidate = self::month_slot( $base, 1, $day, $hour, $minute );
				}
				break;
		}

		return $candidate->getTimestamp();
	}

	/**
	 * Is the schedule due now?
	 *
	 * @param array<string, mixed> $config Schedule config.
	 */
	public static function is_due( array $config, int $last, int $now, \DateTimeZone $tz ): bool {
		$next = self::next_run( $config, $last, $now, $tz );
		return null !== $next && $next <= $now;
	}

	/**
	 * @return array{0:int, 1:int}
	 */
	public static function parse_time( string $time ): array {
		if ( 1 === preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $time ), $m ) ) {
			return [ max( 0, min( 23, (int) $m[1] ) ), max( 0, min( 59, (int) $m[2] ) ) ];
		}
		return [ 2, 0 ];
	}

	private static function month_slot( \DateTimeImmutable $base, int $add_months, int $day, int $hour, int $minute ): \DateTimeImmutable {
		$first = $base->setDate( (int) $base->format( 'Y' ), (int) $base->format( 'n' ), 1 );
		if ( $add_months > 0 ) {
			$first = $first->modify( '+' . $add_months . ' month' );
		}
		$day = min( $day, (int) $first->format( 't' ) );
		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $day )->setTime( $hour, $minute );
	}
}
