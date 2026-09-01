<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use ProfessionalWiki\MemberAccess\Application\AllowlistEntry;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\EntryKind;
use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use ProfessionalWiki\MemberAccess\Persistence\DatabaseAllowlistRepository;
use ProfessionalWiki\MemberAccess\Persistence\DatabaseMemberGroupRepository;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\Persistence\DatabaseAllowlistRepository
 */
class DatabaseAllowlistRepositoryTest extends DatabaseRepositoryTestCase {

	private DatabaseAllowlistRepository $allowlist;
	private DatabaseMemberGroupRepository $groups;

	protected function setUp(): void {
		parent::setUp();

		$connectionProvider = $this->newConnectionProvider();
		$this->allowlist = new DatabaseAllowlistRepository( connectionProvider: $connectionProvider );
		$this->groups = new DatabaseMemberGroupRepository( connectionProvider: $connectionProvider );
	}

	public function testAddedEntryIsReturnedWithItsGroupAndValue(): void {
		$groupId = $this->groups->createGroup( 'Acme' )->id;

		$entry = $this->allowlist->addEntry( $groupId, $this->value( 'jane@example.com' ), 42 );

		$this->assertNotNull( $entry );
		$this->assertSame( $groupId, $entry->groupId );
		$this->assertSame( 'jane@example.com', $entry->value->value );
		$this->assertSame( EntryKind::Email, $entry->value->kind );
		$this->assertSame( 42, $entry->actorId );
	}

	public function testDomainEntryIsStoredAsADomain(): void {
		$groupId = $this->newGroupId();
		$this->addEntry( $groupId, '@example.com' );

		$this->assertSame( EntryKind::Domain, $this->allowlist->listEntries( $groupId )[0]->value->kind );
	}

	public function testAddressEntryIsStoredAsAnAddress(): void {
		$groupId = $this->newGroupId();
		$this->addEntry( $groupId, 'jane@example.com' );

		$this->assertSame( EntryKind::Email, $this->allowlist->listEntries( $groupId )[0]->value->kind );
	}

	public function testAddingActorIsStored(): void {
		$groupId = $this->newGroupId();
		$this->allowlist->addEntry( $groupId, $this->value( 'jane@example.com' ), 42 );

		$this->assertSame( 42, $this->allowlist->listEntries( $groupId )[0]->actorId );
	}

	public function testStoredEntryIsTimestamped(): void {
		$groupId = $this->newGroupId();
		$this->addEntry( $groupId, 'jane@example.com' );

		$this->assertJustHappened( $this->allowlist->listEntries( $groupId )[0]->creationTimestamp );
	}

	public function testStoredEntryCarriesNoInvitation(): void {
		$groupId = $this->newGroupId();
		$this->addEntry( $groupId, 'jane@example.com' );

		$this->assertNull( $this->allowlist->listEntries( $groupId )[0]->invitationTimestamp );
	}

	public function testRecordedInvitationIsReadBackFromTheEntry(): void {
		$entry = $this->addEntry( $this->newGroupId(), 'jane@example.com' );

		$recorded = $this->allowlist->recordInvitation( $entry->id );

		$this->assertSame( $recorded, $this->allowlist->getEntry( $entry->id )?->invitationTimestamp );
	}

	public function testRecordedInvitationIsTimestamped(): void {
		$entry = $this->addEntry( $this->newGroupId(), 'jane@example.com' );

		$this->allowlist->recordInvitation( $entry->id );

		$this->assertJustHappened( $this->allowlist->getEntry( $entry->id )?->invitationTimestamp );
	}

	public function testRecordingAnInvitationLeavesOtherEntriesAlone(): void {
		$groupId = $this->newGroupId();
		$invited = $this->addEntry( $groupId, 'jane@example.com' );
		$uninvited = $this->addEntry( $groupId, 'john@example.com' );

		$this->allowlist->recordInvitation( $invited->id );

		$this->assertNull( $this->allowlist->getEntry( $uninvited->id )?->invitationTimestamp );
	}

	public function testAddedEntryIsListedInItsGroup(): void {
		$groupId = $this->newGroupId();
		$this->addEntry( $groupId, 'jane@example.com' );

		$this->assertSame( [ 'jane@example.com' ], $this->listedValues( $groupId ) );
	}

	public function testEntriesOfOtherGroupsAreNotListed(): void {
		$groupId = $this->newGroupId();
		$this->addEntry( $groupId, 'jane@example.com' );
		$this->addEntry( $this->newGroupId(), 'john@example.org' );

		$this->assertSame( [ 'jane@example.com' ], $this->listedValues( $groupId ) );
	}

	public function testValueAlreadyInAnotherGroupIsRefused(): void {
		$this->addEntry( $this->newGroupId(), 'jane@example.com' );

		$this->assertNull(
			$this->allowlist->addEntry( $this->newGroupId(), $this->value( 'jane@example.com' ), 1 )
		);
	}

	public function testRefusedValueStaysInItsOriginalGroup(): void {
		$originalGroupId = $this->newGroupId();
		$this->addEntry( $originalGroupId, 'jane@example.com' );

		$this->allowlist->addEntry( $this->newGroupId(), $this->value( 'jane@example.com' ), 1 );

		$this->assertSame(
			$originalGroupId,
			$this->groupForValue( 'jane@example.com' )?->id
		);
	}

	public function testEntryIsRetrievedById(): void {
		$groupId = $this->newGroupId();
		$this->addEntry( $groupId, 'first@example.com' );
		$wanted = $this->addEntry( $groupId, 'second@example.com' );
		$this->addEntry( $groupId, 'third@example.com' );

		$this->assertSame( 'second@example.com', $this->allowlist->getEntry( $wanted->id )?->value->value );
	}

	public function testRemovedEntryIsNoLongerRetrieved(): void {
		$entry = $this->addEntry( $this->newGroupId(), 'jane@example.com' );

		$this->allowlist->removeEntry( $entry->id );

		$this->assertNull( $this->allowlist->getEntry( $entry->id ) );
	}

	public function testRemovedEntryIsNoLongerListed(): void {
		$groupId = $this->newGroupId();
		$entry = $this->addEntry( $groupId, 'jane@example.com' );

		$this->allowlist->removeEntry( $entry->id );

		$this->assertSame( [], $this->listedValues( $groupId ) );
	}

	public function testRemovingLeavesOtherEntriesAlone(): void {
		$groupId = $this->newGroupId();
		$this->addEntry( $groupId, 'keep@example.com' );
		$doomed = $this->addEntry( $groupId, 'remove@example.com' );

		$this->allowlist->removeEntry( $doomed->id );

		$this->assertSame( [ 'keep@example.com' ], $this->listedValues( $groupId ) );
	}

	public function testRemovedValueCanBeAddedToAnotherGroup(): void {
		$entry = $this->addEntry( $this->newGroupId(), 'jane@example.com' );
		$this->allowlist->removeEntry( $entry->id );

		$newGroupId = $this->newGroupId();
		$this->addEntry( $newGroupId, 'jane@example.com' );

		$this->assertSame(
			$newGroupId,
			$this->groupForValue( 'jane@example.com' )?->id
		);
	}

	public function testCountingOnlyCountsTheGroupsOwnEntries(): void {
		$groupId = $this->newGroupId();
		$this->addEntry( $groupId, 'first@example.com' );
		$this->addEntry( $groupId, 'second@example.com' );
		$this->addEntry( $this->newGroupId(), 'other@example.com' );

		$this->assertSame( 2, $this->allowlist->countEntries( $groupId ) );
	}

	public function testEmptyGroupCountsZeroEntries(): void {
		$this->assertSame( 0, $this->allowlist->countEntries( $this->newGroupId() ) );
	}

	/**
	 * What decides whether a group may be deleted has to see an entry added moments ago.
	 */
	public function testGroupHoldingAFreshlyAddedEntryIsSaidToHaveEntries(): void {
		$groupId = $this->newGroupId();
		$this->addEntry( $groupId, 'jane@example.com' );

		$this->assertTrue( $this->allowlist->groupHasEntries( $groupId ) );
	}

	public function testGroupOnlyOtherGroupsEntriesBelongToHasNone(): void {
		$this->addEntry( $this->newGroupId(), 'jane@example.com' );

		$this->assertFalse( $this->allowlist->groupHasEntries( $this->newGroupId() ) );
	}

	public function testGroupOfAValueIsFoundByName(): void {
		$this->addEntry( $this->newGroupId(), 'other@example.com' );
		$this->addEntry( $this->groups->createGroup( 'Acme' )->id, 'jane@example.com' );
		$this->addEntry( $this->newGroupId(), 'later@example.com' );

		$this->assertSame( 'Acme', $this->groupForValue( 'jane@example.com' )?->name );
	}

	public function testUnlistedValueHasNoGroup(): void {
		$this->addEntry( $this->newGroupId(), 'jane@example.com' );

		$this->assertNull( $this->groupForValue( 'john@example.com' ) );
	}

	private function groupForValue( string $value ): ?MemberGroup {
		return $this->allowlist->findGroupForValue( $this->value( $value ), ReadConsistency::MayBeStale );
	}

	private function newGroupId(): int {
		return $this->groups->createGroup( 'Group ' . uniqid() )->id;
	}

	private function addEntry( int $groupId, string $value ): AllowlistEntry {
		$entry = $this->allowlist->addEntry( $groupId, $this->value( $value ), 1 );

		$this->assertNotNull( $entry );

		return $entry;
	}

	/**
	 * @return string[]
	 */
	private function listedValues( int $groupId ): array {
		return array_map(
			static fn ( AllowlistEntry $entry ): string => $entry->value->value,
			$this->allowlist->listEntries( $groupId )
		);
	}

	private function value( string $input ): AllowlistValue {
		$value = AllowlistValue::fromString( $input );

		$this->assertNotNull( $value );

		return $value;
	}

}
