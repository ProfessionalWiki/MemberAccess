<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\DeleteGroupResult;
use ProfessionalWiki\MemberAccess\Application\DeleteGroupUseCase;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryAllowlistRepository;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryMemberGroupRepository;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryMemberRepository;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\DeleteGroupUseCase
 */
class DeleteGroupUseCaseTest extends TestCase {

	private InMemoryMemberGroupRepository $groups;
	private InMemoryAllowlistRepository $allowlist;
	private InMemoryMemberRepository $members;

	protected function setUp(): void {
		$this->groups = new InMemoryMemberGroupRepository();
		$this->allowlist = new InMemoryAllowlistRepository( $this->groups );
		$this->members = new InMemoryMemberRepository();
	}

	public function testEmptyGroupIsDeleted(): void {
		$groupId = $this->groups->createGroup( 'Empty' )->id;

		$this->assertSame( DeleteGroupResult::Deleted, $this->newUseCase()->deleteGroup( $groupId ) );
		$this->assertNull( $this->groups->getGroup( $groupId ) );
	}

	public function testGroupWithEntriesIsRefused(): void {
		$groupId = $this->groups->createGroup( 'Populated' )->id;
		$this->addEntry( $groupId, '@example.com' );

		$this->assertSame( DeleteGroupResult::GroupNotEmpty, $this->newUseCase()->deleteGroup( $groupId ) );
	}

	public function testGroupWithEntriesSurvivesTheRefusal(): void {
		$groupId = $this->groups->createGroup( 'Populated' )->id;
		$this->addEntry( $groupId, '@example.com' );

		$this->newUseCase()->deleteGroup( $groupId );

		$this->assertNotNull( $this->groups->getGroup( $groupId ) );
	}

	public function testEntriesOfAnotherGroupDoNotBlockDeletion(): void {
		$groupId = $this->groups->createGroup( 'Empty' )->id;
		$this->addEntry( $this->groups->createGroup( 'Populated' )->id, '@example.com' );

		$this->assertSame( DeleteGroupResult::Deleted, $this->newUseCase()->deleteGroup( $groupId ) );
	}

	public function testGroupWithMembersIsRefused(): void {
		$groupId = $this->groups->createGroup( 'Populated' )->id;
		$this->addMember( $groupId, 'jane@example.com' );

		$this->assertSame( DeleteGroupResult::GroupHasMembers, $this->newUseCase()->deleteGroup( $groupId ) );
	}

	public function testGroupWithMembersSurvivesTheRefusal(): void {
		$groupId = $this->groups->createGroup( 'Populated' )->id;
		$this->addMember( $groupId, 'jane@example.com' );

		$this->newUseCase()->deleteGroup( $groupId );

		$this->assertNotNull( $this->groups->getGroup( $groupId ) );
	}

	public function testMembersOfAnotherGroupDoNotBlockDeletion(): void {
		$groupId = $this->groups->createGroup( 'Empty' )->id;
		$this->addMember( $this->groups->createGroup( 'Populated' )->id, 'jane@example.com' );

		$this->assertSame( DeleteGroupResult::Deleted, $this->newUseCase()->deleteGroup( $groupId ) );
	}

	public function testUnknownGroupIsReported(): void {
		$this->assertSame( DeleteGroupResult::GroupNotFound, $this->newUseCase()->deleteGroup( 404 ) );
	}

	/**
	 * An entry added while the deletion runs has not reached a replica yet, and an entry that
	 * outlived its group admits nobody while still holding the only slot its address has.
	 */
	public function testGroupIsKeptWhenAnEntryHasNotReachedTheReplica(): void {
		$groupId = $this->groups->createGroup( 'Populated' )->id;
		$this->addEntryBehindTheReplica( $groupId, '@example.com' );

		$this->assertSame( DeleteGroupResult::GroupNotEmpty, $this->newUseCase()->deleteGroup( $groupId ) );
		$this->assertNotNull( $this->groups->getGroup( $groupId ) );
	}

	public function testGroupIsKeptWhenAMemberHasNotReachedTheReplica(): void {
		$groupId = $this->groups->createGroup( 'Populated' )->id;
		$this->members->recordMemberBehindTheReplica( 1, $this->normalize( 'jane@example.com' ), $groupId );

		$this->assertSame( DeleteGroupResult::GroupHasMembers, $this->newUseCase()->deleteGroup( $groupId ) );
		$this->assertNotNull( $this->groups->getGroup( $groupId ) );
	}

	public function testGroupThatIsAlreadyGoneOnThePrimaryIsReportedAsNotFound(): void {
		$groupId = $this->groups->createGroup( 'Doomed' )->id;
		$this->groups->deleteGroupBehindTheReplica( $groupId );

		$this->assertSame( DeleteGroupResult::GroupNotFound, $this->newUseCase()->deleteGroup( $groupId ) );
	}

	private function addEntryBehindTheReplica( int $groupId, string $value ): void {
		$allowlistValue = AllowlistValue::fromString( $value );

		$this->assertNotNull( $allowlistValue );

		$this->allowlist->addEntryBehindTheReplica( groupId: $groupId, value: $allowlistValue );
	}

	private function addEntry( int $groupId, string $value ): void {
		$allowlistValue = AllowlistValue::fromString( $value );

		$this->assertNotNull( $allowlistValue );

		$this->allowlist->addEntry( groupId: $groupId, value: $allowlistValue, actorId: 1 );
	}

	private function addMember( int $groupId, string $email ): void {
		$this->members->recordMember(
			userId: count( $this->members->listMembers() ) + 1,
			email: $this->normalize( $email ),
			groupId: $groupId
		);
	}

	private function normalize( string $email ): NormalizedEmail {
		$normalized = NormalizedEmail::fromString( $email );

		$this->assertNotNull( $normalized );

		return $normalized;
	}

	private function newUseCase(): DeleteGroupUseCase {
		return new DeleteGroupUseCase(
			groups: $this->groups,
			allowlist: $this->allowlist,
			members: $this->members
		);
	}

}
