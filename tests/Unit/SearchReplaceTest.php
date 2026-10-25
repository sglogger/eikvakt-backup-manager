<?php
declare( strict_types = 1 );

namespace EikvaktBackupManager\Tests\Unit;

use EikvaktBackupManager\Search_Replace;
use PHPUnit\Framework\TestCase;

final class SearchReplaceTest extends TestCase {

	private array $pairs;

	protected function setUp(): void {
		$this->pairs = Search_Replace::expand_pairs( [ [ 'https://old.example', 'https://www.new-site.example' ] ] );
	}

	public function test_plain_and_json_escaped(): void {
		$this->assertSame( 'Visit https://www.new-site.example/page', Search_Replace::replace( 'Visit https://old.example/page', $this->pairs ) );
		$this->assertSame( '{"url":"https:\/\/www.new-site.example\/a"}', Search_Replace::replace( '{"url":"https:\/\/old.example\/a"}', $this->pairs ) );
	}

	public function test_serialized_lengths_are_recomputed(): void {
		$value  = serialize(
			[
				'home' => 'https://old.example',
				'n'    => 3,
			]
		);
		$result = Search_Replace::replace( $value, $this->pairs );

		$this->assertSame(
			[
				'home' => 'https://www.new-site.example',
				'n'    => 3,
			],
			unserialize( $result )
		);
	}

	public function test_nested_serialization(): void {
		$inner  = serialize( [ 'logo' => 'https://old.example/logo.png' ] );
		$value  = serialize( [ 'widget' => $inner ] );
		$result = unserialize( Search_Replace::replace( $value, $this->pairs ) );

		$this->assertSame( [ 'logo' => 'https://www.new-site.example/logo.png' ], unserialize( $result['widget'] ) );
	}

	public function test_objects_are_never_instantiated_and_survive_unchanged(): void {
		$value  = 'a:2:{s:1:"o";O:8:"Evil_Cls":1:{s:3:"cmd";s:19:"https://old.example";}s:1:"u";s:19:"https://old.example";}';
		$result = Search_Replace::replace( $value, $this->pairs );

		$this->assertStringContainsString( 'O:8:"Evil_Cls":1:{s:3:"cmd";s:19:"https://old.example";}', $result );
		$this->assertStringContainsString( 's:28:"https://www.new-site.example"', $result );
	}

	public function test_untouched_values_are_returned_byte_identical(): void {
		$value = serialize( [ 'f' => 0.1 + 0.2 ] );
		$this->assertSame( $value, Search_Replace::replace( $value, $this->pairs ) );
	}

	public function test_longest_pair_first_and_identity_pairs_dropped(): void {
		$pairs = Search_Replace::expand_pairs( [ [ 'old.example', 'new.example' ], [ 'www.old.example', 'cdn.example' ], [ 'same', 'same' ], [ '', 'x' ] ] );

		$this->assertSame( 'www.old.example', $pairs[0][0] );
		$this->assertCount( 2, $pairs );
		$this->assertSame( 'cdn.example and new.example', Search_Replace::replace( 'www.old.example and old.example', $pairs ) );
	}

	public function test_malformed_serialized_falls_back_to_plain_replace(): void {
		$this->assertSame( 's:5:"https://www.new-site.example";', Search_Replace::replace( 's:5:"https://old.example";', $this->pairs ) );
	}
}
