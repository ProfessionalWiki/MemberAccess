<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\EntryPoints\SchemaChangesHandler;

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

	/**
	 * A column added to a table that already shipped reaches a fresh install through the table
	 * definition, and an install already holding the table through a patch. This is the first.
	 */
	public function testInvitationColumnIsCreated(): void {
		$this->assertTrue( $this->getDb()->fieldExists( 'memberaccess_entry', 'mae_invited', __METHOD__ ) );
	}

	/**
	 * And this is the second. A test wiki has its tables built from the table definition, so
	 * nothing about the column being there says the patch that adds it to an older install was
	 * registered, or that it names a file.
	 */
	public function testInvitationColumnIsRegisteredWithAPatchThatShips(): void {
		$patch = $this->registeredColumnPatches()['memberaccess_entry.mae_invited'] ?? null;

		$this->assertIsString( $patch, 'no patch is registered for the column' );
		$this->assertFileExists( $patch );
	}

	/**
	 * A patch is looked for under the directory named after the database type the wiki runs on, so
	 * one generated for a single type breaks update.php on the installs using the other. The test
	 * wiki exercises one type, which leaves the other to be asserted about.
	 */
	public function testInvitationPatchIsShippedForEveryDatabaseType(): void {
		$this->assertFileExists( __DIR__ . '/../../../sql/mysql/patch-memberaccess_entry-mae_invited.sql' );
		$this->assertFileExists( __DIR__ . '/../../../sql/sqlite/patch-memberaccess_entry-mae_invited.sql' );
	}

	/**
	 * What the handler registers, as the patch file named for each column, keyed by table and
	 * column. The updater is a mock rather than a hand-written double because it is an abstract
	 * core class whose construction the extension has no business reproducing.
	 *
	 * @return array<string, string>
	 */
	private function registeredColumnPatches(): array {
		$registered = [];

		$updater = $this->createMock( DatabaseUpdater::class );
		$updater->method( 'getDB' )->willReturn( $this->getDb() );
		$updater->method( 'addExtensionField' )->willReturnCallback(
			static function ( string $table, string $column, string $patch ) use ( &$registered ): void {
				$registered[$table . '.' . $column] = $patch;
			}
		);

		( new SchemaChangesHandler() )->onLoadExtensionSchemaUpdates( $updater );

		return $registered;
	}

}
