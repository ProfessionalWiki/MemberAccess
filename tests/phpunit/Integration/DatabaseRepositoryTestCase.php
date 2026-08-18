<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Both sides of the connection provider hand out the one test database handle, and the test
 * itself runs inside a transaction, so nothing here can tell a locking read from a plain one, or
 * a primary read from a replica one. The use case tests cover the primary-versus-replica half
 * through doubles that model a lagging replica; that the locking reads lock is asserted nowhere,
 * and rests on the queries saying so. What is testable here is what each query answers.
 */
abstract class DatabaseRepositoryTestCase extends MediaWikiIntegrationTestCase {

	protected function assertJustHappened( ?string $timestamp ): void {
		$this->assertNotNull( $timestamp );
		$this->assertMatchesRegularExpression( '/^\d{14}$/', $timestamp );
		$this->assertGreaterThanOrEqual(
			ConvertibleTimestamp::convert( TS_MW, time() - 60 ),
			$timestamp
		);
	}

	protected function newConnectionProvider(): IConnectionProvider {
		$provider = $this->createMock( IConnectionProvider::class );
		$provider->method( 'getPrimaryDatabase' )->willReturn( $this->getDb() );
		$provider->method( 'getReplicaDatabase' )->willReturn( $this->getDb() );

		return $provider;
	}

}
