<?php
declare( strict_types = 1 );

namespace EikvaktBackupManager\Tests\Unit;

use EikvaktBackupManager\File_Rules;
use PHPUnit\Framework\TestCase;

final class FileRulesTest extends TestCase {

	private const PATTERNS = [ 'content/cache/*', '*/node_modules/*', '*.log' ];

	public function test_without_rules_patterns_decide(): void {
		$rules = new File_Rules( [], self::PATTERNS );

		$this->assertFalse( $rules->excluded_dir( 'content/uploads' ) );
		$this->assertTrue( $rules->excluded_dir( 'content/cache' ) );
		$this->assertTrue( $rules->excluded_file( 'content/cache/page.html', 10 ) );
		$this->assertTrue( $rules->excluded_file( 'content/uploads/debug.log', 10 ) );
		$this->assertFalse( $rules->excluded_file( 'content/uploads/a.jpg', 10 ) );
	}

	public function test_exclude_a_folder_and_include_a_subfolder(): void {
		$rules = new File_Rules(
			[
				'content/uploads'         => 'exclude',
				'content/uploads/2026/10' => 'include',
			],
			self::PATTERNS
		);

		$this->assertTrue( $rules->excluded_file( 'content/uploads/2025/01/a.jpg', 10 ) );
		$this->assertTrue( $rules->excluded_file( 'content/uploads/top.jpg', 10 ) );
		$this->assertFalse( $rules->excluded_file( 'content/uploads/2026/10/a.jpg', 10 ) );

		// The scanner must still walk down to the included folder…
		$this->assertTrue( $rules->descend( 'content/uploads' ) );
		$this->assertTrue( $rules->descend( 'content/uploads/2026' ) );
		// …but not into excluded branches that lead nowhere.
		$this->assertFalse( $rules->descend( 'content/uploads/2025' ) );
	}

	public function test_explicit_include_overrides_default_folder_patterns(): void {
		$rules = new File_Rules( [ 'content/cache' => 'include' ], self::PATTERNS );

		$this->assertFalse( $rules->excluded_dir( 'content/cache' ) );
		$this->assertFalse( $rules->excluded_file( 'content/cache/deep/page.html', 10 ) );
		$this->assertFalse( $rules->excluded_file( 'content/cache/lib/node_modules/x.js', 10 ) );
		// File-name patterns keep applying inside included folders.
		$this->assertTrue( $rules->excluded_file( 'content/cache/error.log', 10 ) );
	}

	public function test_prefix_matching_respects_folder_boundaries(): void {
		$rules = new File_Rules( [ 'content/up' => 'exclude' ], [] );

		$this->assertFalse( $rules->excluded_file( 'content/uploads/a.jpg', 10 ) );
		$this->assertTrue( $rules->excluded_file( 'content/up/a.jpg', 10 ) );
	}

	public function test_size_limit(): void {
		$rules = new File_Rules( [ 'content/uploads' => 'include' ], [], 100 );

		$this->assertTrue( $rules->excluded_file( 'content/uploads/big.zip', 101 ) );
		$this->assertFalse( $rules->excluded_file( 'content/uploads/small.zip', 100 ) );
	}

	public function test_sanitize_rejects_traversal_and_unknown_verdicts(): void {
		$this->assertSame(
			[ 'content/uploads' => 'exclude' ],
			File_Rules::sanitize(
				[
					'content/uploads/' => 'exclude',
					'content/../etc'   => 'exclude',
					'/etc/passwd'      => 'include',
					'root/wp-admin'    => 'maybe',
					'other/x'          => 'include',
				]
			)
		);
	}
}
