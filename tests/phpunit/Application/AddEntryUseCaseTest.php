<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\AddEntryOutcome;
use ProfessionalWiki\MemberAccess\Application\AddEntryResult;
use ProfessionalWiki\MemberAccess\Application\AddEntryUseCase;
use ProfessionalWiki\MemberAccess\Application\EntryKind;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryAllowlistRepository;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryMemberGroupRepository;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\AddEntryUseCase
 */
class AddEntryUseCaseTest extends TestCase {

	private const int ACTOR_ID = 3;

	private InMemoryMemberGroupRepository $groups;
	private InMemoryAllowlistRepository $allowlist;
	private int $groupId;

	protected function setUp(): void {
		$this->groups = new InMemoryMemberGroupRepository();
		$this->allowlist = new InMemoryAllowlistRepository( $this->groups );
		$this->groupId = $this->groups->createGroup( 'Acme' )->id;
	}

	public function testValueThatDoesNotFitTheColumnIsNamedAsTooLong(): void {
		$groupId = $this->groups->createGroup( 'Acme' )->id;

		$result = $this->newUseCase()->addEntry( $groupId, '@' . str_repeat( 'a', 260 ) . '.com', 1 );

		$this->assertSame( AddEntryOutcome::ValueTooLong, $result->outcome );
	}

	public function testAddressIsAdded(): void {
		$result = $this->addEntry( 'jane@example.com' );

		$this->assertSame( AddEntryOutcome::Added, $result->outcome );
		$this->assertSame( 'jane@example.com', $result->entry?->value->value );
		$this->assertSame( EntryKind::Email, $result->entry?->value->kind );
	}

	public function testDomainIsAdded(): void {
		$result = $this->addEntry( '@example.com' );

		$this->assertSame( AddEntryOutcome::Added, $result->outcome );
		$this->assertSame( '@example.com', $result->entry?->value->value );
		$this->assertSame( EntryKind::Domain, $result->entry?->value->kind );
	}

	public function testValueIsNormalized(): void {
		$result = $this->addEntry( '  JANE@Example.COM ' );

		$this->assertSame( 'jane@example.com', $result->entry?->value->value );
	}

	public function testAddedEntryRecordsWhoAddedIt(): void {
		$result = $this->addEntry( 'jane@example.com' );

		$this->assertSame( self::ACTOR_ID, $result->entry?->actorId );
	}

	public function testTextThatIsNoAddressOrDomainIsRefused(): void {
		$result = $this->addEntry( 'example.com' );

		$this->assertSame( AddEntryOutcome::InvalidValue, $result->outcome );
		$this->assertSame( [], $this->allowlist->listEntries( $this->groupId ) );
	}

	public function testBareAtSignIsRefused(): void {
		$this->assertSame( AddEntryOutcome::InvalidValue, $this->addEntry( '@' )->outcome );
	}

	public function testUnknownGroupIsRefused(): void {
		$result = $this->newUseCase()->addEntry( $this->groupId + 100, 'jane@example.com', self::ACTOR_ID );

		$this->assertSame( AddEntryOutcome::GroupNotFound, $result->outcome );
	}

	/**
	 * A group deleted while the entry is being added has not gone from a replica yet, and an entry
	 * that outlived its group admits nobody while still holding the only slot its address has.
	 */
	public function testGroupThatIsAlreadyGoneOnThePrimaryTakesNoEntry(): void {
		$this->groups->deleteGroupBehindTheReplica( $this->groupId );

		$result = $this->addEntry( 'jane@example.com' );

		$this->assertSame( AddEntryOutcome::GroupNotFound, $result->outcome );
		$this->assertSame( [], $this->allowlist->listEntries( $this->groupId ) );
	}

	public function testValueThatIsAlreadyInTheSameGroupIsRefused(): void {
		$this->addEntry( 'jane@example.com' );

		$result = $this->addEntry( 'jane@example.com' );

		$this->assertSame( AddEntryOutcome::DuplicateValue, $result->outcome );
		$this->assertCount( 1, $this->allowlist->listEntries( $this->groupId ) );
	}

	public function testDuplicateNamesTheGroupThatAlreadyHasTheValue(): void {
		$this->addEntry( 'jane@example.com' );
		$other = $this->groups->createGroup( 'Umbrella' );

		$result = $this->newUseCase()->addEntry( $other->id, 'jane@example.com', self::ACTOR_ID );

		$this->assertSame( AddEntryOutcome::DuplicateValue, $result->outcome );
		$this->assertSame( 'Acme', $result->conflictingGroup?->name );
	}

	private function addEntry( string $value ): AddEntryResult {
		return $this->newUseCase()->addEntry( $this->groupId, $value, self::ACTOR_ID );
	}

	private function newUseCase(): AddEntryUseCase {
		return new AddEntryUseCase( groups: $this->groups, allowlist: $this->allowlist );
	}

}
