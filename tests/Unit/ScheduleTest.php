<?php
declare( strict_types = 1 );

namespace EikvaktBackupManager\Tests\Unit;

use EikvaktBackupManager\Schedule;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase {

	private \DateTimeZone $tz;

	protected function setUp(): void {
		$this->tz = new \DateTimeZone( 'Europe/Zurich' );
	}

	private function at( string $local ): int {
		return ( new \DateTimeImmutable( $local, $this->tz ) )->getTimestamp();
	}

	public function test_off_is_never_due(): void {
		$this->assertNull( Schedule::next_run( [ 'frequency' => 'off' ], 0, time(), $this->tz ) );
	}

	public function test_daily_next_slot(): void {
		$config = [
			'frequency' => 'daily',
			'time'      => '02:00',
		];

		$this->assertSame( $this->at( '2026-10-20 02:00' ), Schedule::next_run( $config, $this->at( '2026-10-19 02:00' ), $this->at( '2026-10-19 12:00' ), $this->tz ) );
		$this->assertSame( $this->at( '2026-10-19 02:00' ), Schedule::next_run( $config, $this->at( '2026-10-18 23:00' ), $this->at( '2026-10-19 12:00' ), $this->tz ) );
		$this->assertTrue( Schedule::is_due( $config, $this->at( '2026-10-18 23:00' ), $this->at( '2026-10-19 12:00' ), $this->tz ) );
	}

	public function test_daily_keeps_local_time_across_dst(): void {
		$config = [
			'frequency' => 'daily',
			'time'      => '02:30',
		];
		// Night of the switch to winter time (25 October 2026 in Europe).
		$next = Schedule::next_run( $config, $this->at( '2026-10-24 02:30' ), $this->at( '2026-10-24 12:00' ), $this->tz );
		$this->assertSame( '2026-10-25', ( new \DateTimeImmutable( '@' . $next ) )->setTimezone( $this->tz )->format( 'Y-m-d' ) );
		$this->assertSame( $this->at( '2026-10-26 02:30' ), Schedule::next_run( $config, $next, $next + 60, $this->tz ) );
	}

	public function test_weekly(): void {
		// 2026-10-19 is a Monday.
		$config = [
			'frequency' => 'weekly',
			'weekday'   => 3,
			'time'      => '01:15',
		];
		$this->assertSame( $this->at( '2026-10-21 01:15' ), Schedule::next_run( $config, $this->at( '2026-10-19 08:00' ), $this->at( '2026-10-19 08:00' ), $this->tz ) );
		$this->assertSame( $this->at( '2026-10-28 01:15' ), Schedule::next_run( $config, $this->at( '2026-10-21 01:15' ), $this->at( '2026-10-21 01:16' ), $this->tz ) );
	}

	public function test_monthly_clamps_to_month_length(): void {
		$config = [
			'frequency' => 'monthly',
			'monthday'  => 31,
			'time'      => '03:00',
		];
		$this->assertSame( $this->at( '2026-11-30 03:00' ), Schedule::next_run( $config, $this->at( '2026-10-31 03:00' ), $this->at( '2026-10-31 04:00' ), $this->tz ) );
	}

	public function test_interval(): void {
		$config = [
			'frequency'      => 'interval',
			'interval_hours' => 6,
		];
		$this->assertSame( 1000 + 6 * 3600, Schedule::next_run( $config, 1000, 2000, $this->tz ) );
		$this->assertSame( 5000, Schedule::next_run( $config, 0, 5000, $this->tz ) );
	}

	public function test_fresh_daily_schedule_waits_for_first_slot(): void {
		$config = [
			'frequency' => 'daily',
			'time'      => '02:00',
		];
		$this->assertFalse( Schedule::is_due( $config, 0, $this->at( '2026-10-19 12:00' ), $this->tz ) );
	}
}
