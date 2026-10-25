<?php
declare( strict_types = 1 );

namespace EikvaktBackupManager\Tests\Unit;

use EikvaktBackupManager\File_Index;
use PHPUnit\Framework\TestCase;

final class FileIndexTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/ekbm-index-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->dir . '/*' ) ?: [] );
		rmdir( $this->dir );
	}

	public function test_full_backup_archives_everything(): void {
		file_put_contents( $this->dir . '/scan.txt', "root/a.php\t10\t100\ncontent/b.txt\t20\t200\n" );

		$stats = File_Index::diff( $this->dir . '/scan.txt', [], 'full1', $this->dir . '/index.gz', $this->dir . '/changes.txt' );

		$this->assertSame( 2, $stats['files'] );
		$this->assertSame( 2, $stats['changed'] );
		$this->assertSame( 30, $stats['bytes'] );

		$index = File_Index::load( $this->dir . '/index.gz' );
		$this->assertSame( [ 10, 100, 'full1' ], $index['root/a.php'] );
	}

	public function test_incremental_keeps_holders_and_detects_changes_and_deletions(): void {
		$previous = [
			'root/a.php'    => [ 10, 100, 'full1' ],
			'content/b.txt' => [ 20, 200, 'full1' ],
			'content/c.txt' => [ 30, 300, 'inc1' ],
		];

		// a unchanged, b modified (mtime), c deleted, d new.
		file_put_contents( $this->dir . '/scan.txt', "root/a.php\t10\t100\ncontent/b.txt\t20\t250\ncontent/d.txt\t5\t400\n" );

		$stats = File_Index::diff( $this->dir . '/scan.txt', $previous, 'inc2', $this->dir . '/index.gz', $this->dir . '/changes.txt' );

		$this->assertSame( 3, $stats['files'] );
		$this->assertSame( 2, $stats['changed'] );
		$this->assertSame( 1, $stats['deleted'] );

		$index = File_Index::load( $this->dir . '/index.gz' );
		$this->assertSame( 'full1', $index['root/a.php'][2] );
		$this->assertSame( 'inc2', $index['content/b.txt'][2] );
		$this->assertSame( 'inc2', $index['content/d.txt'][2] );
		$this->assertArrayNotHasKey( 'content/c.txt', $index );

		$changes = file( $this->dir . '/changes.txt', FILE_IGNORE_NEW_LINES );
		$this->assertSame( [ "content/b.txt\t20\t250", "content/d.txt\t5\t400" ], $changes );
	}

	public function test_parse_line_ignores_comments_and_garbage(): void {
		$this->assertNull( File_Index::parse_line( File_Index::HEADER . "\n" ) );
		$this->assertNull( File_Index::parse_line( "broken\n" ) );
		$this->assertSame( [ 'root/x', 1, 2, '' ], File_Index::parse_line( "root/x\t1\t2\n" ) );
	}
}
