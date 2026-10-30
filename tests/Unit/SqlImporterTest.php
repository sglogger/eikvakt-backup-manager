<?php
declare( strict_types = 1 );

namespace EikvaktBackupManager\Tests\Unit;

use EikvaktBackupManager\Sql_Importer;
use PHPUnit\Framework\TestCase;

final class SqlImporterTest extends TestCase {

	public function test_maps_prefix_change_to_temporary_names(): void {
		$map = Sql_Importer::map_tables( [ 'wp_options', 'wp_posts' ], 'wp_', 'wpt_', 'abc123' );

		$this->assertSame( 'wpt_options', $map['wp_options']['target'] );
		$this->assertSame( 'afbtabc123_wpt_options', $map['wp_options']['temp'] );
	}

	public function test_rewrites_only_selected_tables(): void {
		$map = Sql_Importer::map_tables( [ 'wp_options' ], 'wp_', 'wp_', 't1' );

		$this->assertSame( "INSERT INTO `afbtt1_wp_options` VALUES (1,'a');", Sql_Importer::rewrite( "INSERT INTO `wp_options` VALUES (1,'a');", $map ) );
		$this->assertSame( 'CREATE TABLE `afbtt1_wp_options` (x int);', Sql_Importer::rewrite( 'CREATE TABLE `wp_options` (x int);', $map ) );
		$this->assertNull( Sql_Importer::rewrite( 'INSERT INTO `wp_users` VALUES (1);', $map ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function disallowed_lines(): array {
		return [
			'update'                   => [ "UPDATE `wp_users` SET user_pass='x';" ],
			'delete'                   => [ 'DELETE FROM wp_users;' ],
			'alter'                    => [ 'ALTER TABLE `wp_options` ADD x int;' ],
			'lower-case insert'        => [ 'insert into `wp_options` values (1);' ],
			'insert select'            => [ 'INSERT INTO `wp_options` SELECT * FROM wp_users;' ],
			'insert no semicolon'      => [ 'INSERT INTO `wp_options` VALUES (1)' ],
			'drop other shape'         => [ 'DROP TABLE `wp_options`;' ],
			'drop with suffix'         => [ 'DROP TABLE IF EXISTS `wp_options`; DROP TABLE wp_users;' ],
			'create as select'         => [ 'CREATE TABLE `wp_options` AS SELECT * FROM wp_users;' ],
			'data directory'           => [ "CREATE TABLE `wp_options` (id int) DATA DIRECTORY='/tmp';" ],
			'index directory'          => [ "CREATE TABLE `wp_options` (id int) INDEX  DIRECTORY = '/tmp';" ],
			'grant'                    => [ "GRANT ALL ON *.* TO 'x'@'%';" ],
			'set other'                => [ 'SET GLOBAL general_log = 1;' ],
			// External review 30 October 2026 (F4) and relatives.
			'create columns as select' => [ 'CREATE TABLE `wp_options` (id int) AS SELECT id FROM wp_users;' ],
			'merge engine'             => [ 'CREATE TABLE `wp_options` (id int) ENGINE=MRG_MyISAM UNION=(wp_users) INSERT_METHOD=LAST;' ],
			'federated engine'         => [ "CREATE TABLE `wp_options` (id int) ENGINE=FEDERATED CONNECTION='mysql://u@h/db/t';" ],
			'versioned comment'        => [ 'CREATE TABLE `wp_options` (id int /*!50000 ) ENGINE=MRG_MyISAM UNION=(wp_users */);' ],
			'subquery in body'         => [ 'CREATE TABLE `wp_options` (id int DEFAULT (SELECT 1));' ],
			'load_file in body'        => [ "CREATE TABLE `wp_options` (x text DEFAULT (LOAD_FILE('/etc/passwd')));" ],
			'unknown option'           => [ 'CREATE TABLE `wp_options` (id int) ENGINE=InnoDB PARTITION BY HASH(id);' ],
			'insert subquery'          => [ 'INSERT INTO `wp_options` VALUES ((SELECT user_pass FROM wp_users LIMIT 1));' ],
			'insert load_file'         => [ "INSERT INTO `wp_options` VALUES (LOAD_FILE('/etc/passwd'));" ],
			'insert function'          => [ 'INSERT INTO `wp_options` VALUES (1,SLEEP(5));' ],
			'insert later tuple'       => [ "INSERT INTO `wp_options` VALUES (1,'a'),(2,x());" ],
			'insert hex literal'       => [ 'INSERT INTO `wp_options` VALUES (0x41);' ],
			'insert open string'       => [ "INSERT INTO `wp_options` VALUES ('a\\');" ],
		];
	}

	public function test_real_dump_lines_are_accepted(): void {
		$map = Sql_Importer::map_tables( [ 'wp_options' ], 'wp_', 'wp_', 't1' );

		$lines = [
			"CREATE TABLE `wp_options` (   `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,   `option_name` varchar(191) NOT NULL DEFAULT '',   `option_value` longtext NOT NULL,   `autoload` varchar(20) NOT NULL DEFAULT 'yes',   PRIMARY KEY (`option_id`),   UNIQUE KEY `option_name` (`option_name`),   KEY `autoload` (`autoload`) ) ENGINE=InnoDB AUTO_INCREMENT=123 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;",
			"CREATE TABLE `wp_options` (   `id` int(11) NOT NULL,   `selected` tinyint(1) DEFAULT 0 COMMENT 'SELECT (this)' ) ENGINE=Aria DEFAULT CHARSET=utf8mb4 PAGE_CHECKSUM=1 TRANSACTIONAL=1 COMMENT='it''s (fine)';",
			"INSERT INTO `wp_options` VALUES (1,'it\\'s','a''b','\\\\',NULL,'(SELECT 1); DROP TABLE x;'),(2,'',NULL,'x','y','z');",
			'INSERT INTO `wp_options` VALUES (-1.5e3,0,NULL);',
		];

		foreach ( $lines as $line ) {
			$this->assertNotNull( Sql_Importer::rewrite( $line, $map ), $line );
		}
	}

	/**
	 * @dataProvider disallowed_lines
	 */
	public function test_anything_but_the_dump_format_is_rejected( string $line ): void {
		$map = Sql_Importer::map_tables( [ 'wp_options', 'wp_users' ], 'wp_', 'wp_', 't1' );

		$this->assertFalse( Sql_Importer::is_header_line( $line ) );
		$this->expectException( \UnexpectedValueException::class );
		Sql_Importer::rewrite( $line, $map );
	}

	public function test_header_lines_are_recognised(): void {
		foreach ( [ '-- ekbm-dump 1 prefix=wp_', '/*!40101 SET NAMES utf8mb4 */;', 'SET FOREIGN_KEY_CHECKS=0;', "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';", '' ] as $line ) {
			$this->assertTrue( Sql_Importer::is_header_line( $line ), $line );
		}
	}

	public function test_semicolons_inside_values_are_fine(): void {
		$map = Sql_Importer::map_tables( [ 'wp_options' ], 'wp_', 'wp_', 't1' );
		$this->assertSame(
			"INSERT INTO `afbtt1_wp_options` VALUES (1,'a;b'),(2,'DROP TABLE x;');",
			Sql_Importer::rewrite( "INSERT INTO `wp_options` VALUES (1,'a;b'),(2,'DROP TABLE x;');", $map )
		);
	}

	public function test_table_name_inside_values_is_not_touched(): void {
		$map  = Sql_Importer::map_tables( [ 'wp_options' ], 'wp_', 'wp_', 't1' );
		$line = "INSERT INTO `wp_options` VALUES ('INSERT INTO `wp_options`');";

		$this->assertSame( "INSERT INTO `afbtt1_wp_options` VALUES ('INSERT INTO `wp_options`');", Sql_Importer::rewrite( $line, $map ) );
	}
}
