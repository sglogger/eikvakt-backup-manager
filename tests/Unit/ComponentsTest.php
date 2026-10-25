<?php
declare( strict_types = 1 );

namespace EikvaktBackupManager\Tests\Unit;

use EikvaktBackupManager\Components;
use PHPUnit\Framework\TestCase;

final class ComponentsTest extends TestCase {

	public function test_classifies_archive_paths(): void {
		$this->assertSame( Components::CORE, Components::classify( 'root/wp-includes/version.php' ) );
		$this->assertSame( Components::CORE, Components::classify( 'root/wp-config.php' ) );
		$this->assertSame( Components::PLUGINS, Components::classify( 'content/plugins/akismet/akismet.php' ) );
		$this->assertSame( Components::PLUGINS, Components::classify( 'content/mu-plugins/loader.php' ) );
		$this->assertSame( Components::THEMES, Components::classify( 'content/themes/twentytwentyfive/style.css' ) );
		$this->assertSame( Components::UPLOADS, Components::classify( 'content/uploads/2026/10/a.jpg' ) );
		$this->assertSame( Components::CONTENT, Components::classify( 'content/languages/de_DE.mo' ) );
		$this->assertSame( Components::CONTENT, Components::classify( 'content/plugins-old.php' ) );
	}

	public function test_sanitize_keeps_known_components_in_canonical_order(): void {
		$this->assertSame(
			[ Components::DATABASE, Components::PLUGINS, Components::UPLOADS ],
			Components::sanitize( [ 'uploads', 'evil', 'plugins', 'database' ] )
		);
		$this->assertSame( [ Components::PLUGINS ], Components::sanitize( [ 'database', 'plugins' ], false ) );
		$this->assertSame( [], Components::sanitize( 'nonsense' ) );
	}
}
