<?php
declare( strict_types = 1 );

namespace EikvaktBackupManager\Tests\Unit;

use EikvaktBackupManager\Manifest;
use PHPUnit\Framework\TestCase;

final class ManifestTest extends TestCase {

	public function test_new_id_is_valid_sortable_and_typed(): void {
		$id = Manifest::new_id( 'www.Example.com', Manifest::TYPE_INCREMENTAL, 1792413600, 'a1b2c3d4e5f6' );

		$this->assertSame( 'www-example-com_20261019-124000_i_a1b2c3d4e5f6', $id );
		$this->assertTrue( Manifest::is_valid_id( $id ) );
	}

	public function test_rejects_ids_that_could_escape_a_path(): void {
		foreach ( [ '../etc/passwd', 'a_20261019-120000_f_xyz', 'site_20261019-120000_f_a1b2c3d4/..', '' ] as $bad ) {
			$this->assertFalse( Manifest::is_valid_id( $bad ), $bad );
		}
	}

	public function test_from_json_refuses_invalid_ids(): void {
		$this->assertNull( Manifest::from_json( '{"id":"../../x"}' ) );
		$this->assertNull( Manifest::from_json( 'not json' ) );
	}

	public function test_files_and_manifest_last(): void {
		$m = new Manifest( [ 'id' => 'site_20261019-120000_f_a1b2c3d4' ] );
		$m->add_file( 'site_20261019-120000_f_a1b2c3d4.files-001.zip', Manifest::KIND_FILES, 100 );
		$m->add_file( 'site_20261019-120000_f_a1b2c3d4.db.sql.gz', Manifest::KIND_DB, 50 );
		$m->add_file( 'site_20261019-120000_f_a1b2c3d4.files-001.zip', Manifest::KIND_FILES, 120 );

		$this->assertSame( 170, $m->total_size() );
		$this->assertSame( [ 'site_20261019-120000_f_a1b2c3d4.db.sql.gz' ], $m->files_of_kind( Manifest::KIND_DB ) );
		$this->assertSame( 'site_20261019-120000_f_a1b2c3d4.manifest.json', array_slice( $m->all_file_names(), -1 )[0] );

		$copy = Manifest::from_json( $m->to_json() );
		$this->assertNotNull( $copy );
		$this->assertSame( $m->files(), $copy->files() );
	}
}
