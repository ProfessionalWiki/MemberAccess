<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Persistence;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\IssuedCode;
use ProfessionalWiki\MemberAccess\Persistence\StashCodeRepository;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\StaleRecordStash;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \ProfessionalWiki\MemberAccess\Persistence\StashCodeRepository
 */
class StashCodeRepositoryTest extends TestCase {

	private float $time = 1000.0;
	private HashBagOStuff $stash;

	protected function setUp(): void {
		$this->stash = new HashBagOStuff();
		$this->stash->setMockTime( $this->time );
	}

	public function testStoredCodeIsReturned(): void {
		$repository = $this->newRepository();
		$code = new IssuedCode( email: 'jane@example.com', codeHash: 'the-hash' );

		$repository->store( handle: 'handle', code: $code, ttlInSeconds: 600 );

		$this->assertEquals( $code, $repository->get( 'handle' ) );
	}

	public function testCodesAreKeptApartByHandle(): void {
		$repository = $this->newRepository();
		$repository->store( 'first', new IssuedCode( 'first@example.com', 'first-hash' ), 600 );
		$repository->store( 'second', new IssuedCode( 'second@example.com', 'second-hash' ), 600 );

		$this->assertSame( 'second@example.com', $repository->get( 'second' )?->email );
	}

	public function testUnknownHandleHasNoCode(): void {
		$this->assertNull( $this->newRepository()->get( 'never-stored' ) );
	}

	public function testCodeSurvivesUntilItsTimeToLiveRunsOut(): void {
		$repository = $this->newRepository();
		$repository->store( 'handle', new IssuedCode( 'jane@example.com', 'the-hash' ), 600 );

		$this->time += 599;

		$this->assertNotNull( $repository->get( 'handle' ) );
	}

	public function testCodeIsGoneAfterItsTimeToLive(): void {
		$repository = $this->newRepository();
		$repository->store( 'handle', new IssuedCode( 'jane@example.com', 'the-hash' ), 600 );

		$this->time += 601;

		$this->assertNull( $repository->get( 'handle' ) );
	}

	public function testDeletedCodeIsGone(): void {
		$repository = $this->newRepository();
		$repository->store( 'handle', new IssuedCode( 'jane@example.com', 'the-hash' ), 600 );

		$repository->delete( 'handle' );

		$this->assertNull( $repository->get( 'handle' ) );
	}

	public function testDeletingLeavesOtherCodesAlone(): void {
		$repository = $this->newRepository();
		$repository->store( 'kept', new IssuedCode( 'jane@example.com', 'the-hash' ), 600 );
		$repository->store( 'deleted', new IssuedCode( 'john@example.com', 'other-hash' ), 600 );

		$repository->delete( 'deleted' );

		$this->assertNotNull( $repository->get( 'kept' ) );
	}

	public function testRecordWithUnknownFieldsIsIgnored(): void {
		$repository = new StashCodeRepository( stash: new StaleRecordStash() );

		$this->assertNull( $repository->get( 'handle' ) );
	}

	public function testRecordThatIsNotAnArrayIsIgnored(): void {
		$staleRecord = (object)[ 'email' => 'jane@example.com', 'hash' => 'the-hash' ];

		$repository = new StashCodeRepository( stash: new StaleRecordStash( $staleRecord ) );

		$this->assertNull( $repository->get( 'handle' ) );
	}

	private function newRepository(): StashCodeRepository {
		return new StashCodeRepository( stash: $this->stash );
	}

}
