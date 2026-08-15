<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Persistence;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Persistence\StashCounterStore;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\UnavailableStash;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \ProfessionalWiki\MemberAccess\Persistence\StashCounterStore
 */
class StashCounterStoreTest extends TestCase {

	private float $time = 1000.0;
	private HashBagOStuff $stash;
	private SpyLogger $logger;

	protected function setUp(): void {
		$this->stash = new HashBagOStuff();
		$this->stash->setMockTime( $this->time );
		$this->logger = new SpyLogger();
	}

	public function testFirstIncrementCountsOne(): void {
		$this->assertSame( 1, $this->newStore()->increment( 'key', 60 ) );
	}

	public function testCountRisesWithEachIncrement(): void {
		$store = $this->newStore();
		$store->increment( 'key', 60 );
		$store->increment( 'key', 60 );

		$this->assertSame( 3, $store->increment( 'key', 60 ) );
	}

	public function testCountersAreKeptApartByKey(): void {
		$store = $this->newStore();
		$store->increment( 'first', 60 );
		$store->increment( 'first', 60 );

		$this->assertSame( 1, $store->increment( 'second', 60 ) );
	}

	public function testCountSurvivesUntilItsTimeToLiveRunsOut(): void {
		$store = $this->newStore();
		$store->increment( 'key', 60 );

		$this->time += 59;

		$this->assertSame( 2, $store->increment( 'key', 60 ) );
	}

	public function testCountRestartsAfterItsTimeToLive(): void {
		$store = $this->newStore();
		$store->increment( 'key', 60 );

		$this->time += 61;

		$this->assertSame( 1, $store->increment( 'key', 60 ) );
	}

	public function testTimeToLiveIsNotExtendedByLaterIncrements(): void {
		$store = $this->newStore();
		$store->increment( 'key', 60 );

		$this->time += 30;
		$store->increment( 'key', 60 );
		$this->time += 31;

		$this->assertSame( 1, $store->increment( 'key', 60 ) );
	}

	public function testCountIsUnknownWhenTheStashCannotBeReached(): void {
		$this->stash = new UnavailableStash();

		$this->assertNull( $this->newStore()->increment( 'key', 60 ) );
	}

	public function testStashThatCannotBeReachedIsLoggedAsAnError(): void {
		$this->stash = new UnavailableStash();

		$this->newStore()->increment( 'key', 60 );

		$this->assertCount( 1, $this->logger->getEntriesAtLevel( 'error' ) );
	}

	private function newStore(): StashCounterStore {
		return new StashCounterStore( stash: $this->stash, logger: $this->logger );
	}

}
