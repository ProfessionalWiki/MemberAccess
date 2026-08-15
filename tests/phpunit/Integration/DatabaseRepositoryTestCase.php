<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;

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
