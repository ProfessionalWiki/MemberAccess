<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\SchemaChangesHandler
 */
class SchemaChangesHandlerTest extends MediaWikiIntegrationTestCase {

	/**
	 * @dataProvider extensionTableProvider
	 */
	public function testExtensionTableIsCreated( string $table ): void {
		$this->assertTrue( $this->getDb()->tableExists( $table, __METHOD__ ) );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function extensionTableProvider(): iterable {
		yield 'groups' => [ 'memberaccess_group' ];
		yield 'allowlist entries' => [ 'memberaccess_entry' ];
		yield 'members' => [ 'memberaccess_member' ];
	}

}
