<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Persistence\DatabaseSchema;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IMaintainableDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\Persistence\DatabaseSchema
 */
class DatabaseSchemaTest extends MediaWikiIntegrationTestCase {

	private SpyLogger $logger;

	private int $probes = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = new SpyLogger();
	}

	public function testTablesTheWikiHasAreNotReportedMissing(): void {
		$this->assertFalse( $this->newSchema( $this->wikiConnections() )->isMissing() );
	}

	public function testWikiWithTheTablesIsNotWarnedAbout(): void {
		$this->newSchema( $this->wikiConnections() )->isMissing();

		$this->assertSame( [], $this->logger->getEntries() );
	}

	public function testTablesTheWikiLacksAreReportedMissing(): void {
		$this->assertTrue( $this->newSchema( $this->connectionsReporting( tablesExist: false ) )->isMissing() );
	}

	/**
	 * The wiki has loaded the extension and stopped short of installing it, which nothing but the
	 * log can say.
	 */
	public function testMissingTablesAreWarnedAboutWithWhatToRun(): void {
		$this->newSchema( $this->connectionsReporting( tablesExist: false ) )->isMissing();

		$this->assertStringContainsString(
			'update.php',
			implode( "\n", $this->logger->getEntriesAtLevel( 'warning' ) )
		);
	}

	public function testTheWarningIsNotRepeated(): void {
		$schema = $this->newSchema( $this->connectionsReporting( tablesExist: false ) );

		$schema->isMissing();
		$schema->isMissing();

		$this->assertCount( 1, $this->logger->getEntriesAtLevel( 'warning' ) );
	}

	/**
	 * Every request builds the entry points, and most of them go on to do nothing with the tables.
	 */
	public function testTheDatabaseIsNotAskedUntilTheQuestionIsPut(): void {
		$this->newSchema( $this->connectionsReporting( tablesExist: true ) );

		$this->assertSame( 0, $this->probes );
	}

	/**
	 * Everything the extension does asks first, so the answer has to cost at most one query.
	 */
	public function testTheDatabaseIsAskedOnlyOnce(): void {
		$schema = $this->newSchema( $this->connectionsReporting( tablesExist: true ) );

		$schema->isMissing();
		$schema->isMissing();

		$this->assertSame( 1, $this->probes );
	}

	/**
	 * A connection that cannot be asked is no reason to turn the extension off wiki-wide.
	 */
	public function testConnectionThatCannotBeAskedIsTakenToHaveTheTables(): void {
		$this->assertFalse( $this->newSchema( $this->connectionsThatCannotBeAsked() )->isMissing() );
	}

	private function newSchema( IConnectionProvider $connections ): DatabaseSchema {
		return new DatabaseSchema( connectionProvider: $connections, logger: $this->logger );
	}

	private function wikiConnections(): IConnectionProvider {
		return $this->getServiceContainer()->getConnectionProvider();
	}

	private function connectionsReporting( bool $tablesExist ): IConnectionProvider {
		$database = $this->createStub( IMaintainableDatabase::class );
		$database->method( 'tableExists' )->willReturnCallback( function () use ( $tablesExist ): bool {
			$this->probes++;

			return $tablesExist;
		} );

		return $this->connectionsTo( $database );
	}

	private function connectionsThatCannotBeAsked(): IConnectionProvider {
		return $this->connectionsTo( $this->createStub( IReadableDatabase::class ) );
	}

	private function connectionsTo( IReadableDatabase $database ): IConnectionProvider {
		$connections = $this->createStub( IConnectionProvider::class );
		$connections->method( 'getReplicaDatabase' )->willReturn( $database );

		return $connections;
	}

}
