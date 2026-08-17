<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use ProfessionalWiki\MemberAccess\Application\Member;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use ProfessionalWiki\MemberAccess\Persistence\DatabaseMemberRepository;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\Persistence\DatabaseMemberRepository
 */
class DatabaseMemberRepositoryTest extends DatabaseRepositoryTestCase {

	private DatabaseMemberRepository $members;

	protected function setUp(): void {
		parent::setUp();

		$this->members = new DatabaseMemberRepository( connectionProvider: $this->newConnectionProvider() );
	}

	public function testRecordedMemberKeepsItsEmailAndGroup(): void {
		$this->recordMember( userId: 7, email: 'jane@example.com', groupId: 3 );

		$member = $this->members->getMember( 7, ReadConsistency::UpToDate );

		$this->assertNotNull( $member );
		$this->assertSame( 'jane@example.com', $member->email );
		$this->assertSame( 3, $member->groupId );
	}

	public function testRecordedMemberIsActive(): void {
		$this->recordMember( userId: 7, email: 'jane@example.com', groupId: 3 );

		$this->assertTrue( $this->members->getMember( 7, ReadConsistency::UpToDate )?->isActive() );
	}

	public function testRecordedMemberIsTimestamped(): void {
		$this->recordMember( userId: 7, email: 'jane@example.com', groupId: 3 );

		$this->assertJustHappened( $this->members->getMember( 7, ReadConsistency::UpToDate )?->creationTimestamp );
	}

	public function testUnknownUserIsNoMember(): void {
		$this->recordMember( userId: 7, email: 'jane@example.com', groupId: 3 );

		$this->assertNull( $this->members->getMember( 8, ReadConsistency::UpToDate ) );
	}

	public function testRecordedMemberHasNotLoggedInYet(): void {
		$this->recordMember( userId: 7, email: 'jane@example.com', groupId: 3 );

		$this->assertNull( $this->members->getMember( 7, ReadConsistency::UpToDate )?->lastLoginTimestamp );
	}

	public function testLoginIsTimestamped(): void {
		$this->recordMember( userId: 7, email: 'jane@example.com', groupId: 3 );

		$this->members->recordLogin( 7 );

		$this->assertJustHappened( $this->members->getMember( 7, ReadConsistency::UpToDate )?->lastLoginTimestamp );
	}

	public function testLoginLeavesOtherMembersAlone(): void {
		$this->recordMember( userId: 7, email: 'jane@example.com', groupId: 3 );
		$this->recordMember( userId: 8, email: 'john@example.com', groupId: 3 );

		$this->members->recordLogin( 7 );

		$this->assertNull( $this->members->getMember( 8, ReadConsistency::UpToDate )?->lastLoginTimestamp );
	}

	public function testLoginOfSomeoneWhoIsNoMemberIsIgnored(): void {
		$this->members->recordLogin( 7 );

		$this->assertNull( $this->members->getMember( 7, ReadConsistency::UpToDate ) );
	}

	public function testMemberIsFoundByTheirAddress(): void {
		$this->recordMember( userId: 7, email: 'jane@example.com', groupId: 3 );
		$this->recordMember( userId: 8, email: 'john@example.com', groupId: 3 );
		$this->recordMember( userId: 9, email: 'jack@example.com', groupId: 3 );

		$this->assertSame( 8, $this->findMember( 'john@example.com' )?->userId );
	}

	public function testAddressOfNoMemberFindsNobody(): void {
		$this->recordMember( userId: 7, email: 'jane@example.com', groupId: 3 );

		$this->assertNull( $this->findMember( 'john@example.com' ) );
	}

	public function testRosterListsEveryMember(): void {
		$this->recordMember( userId: 1, email: 'first@example.com', groupId: 1 );
		$this->recordMember( userId: 2, email: 'second@example.com', groupId: 1 );
		$this->recordMember( userId: 3, email: 'third@example.com', groupId: 2 );

		$this->assertSame(
			[ 'first@example.com', 'second@example.com', 'third@example.com' ],
			array_map( static fn ( Member $member ): string => $member->email, $this->members->listMembers() )
		);
	}

	public function testRosterIsEmptyWithoutMembers(): void {
		$this->assertSame( [], $this->members->listMembers() );
	}

	public function testDeactivatedMemberIsNoLongerActive(): void {
		$this->recordMember( userId: 7, email: 'jane@example.com', groupId: 3 );

		$this->members->deactivateMember( 7 );

		$this->assertFalse( $this->members->getMember( 7, ReadConsistency::UpToDate )?->isActive() );
	}

	public function testDeactivationIsTimestamped(): void {
		$this->recordMember( userId: 7, email: 'jane@example.com', groupId: 3 );

		$this->members->deactivateMember( 7 );

		$this->assertJustHappened( $this->members->getMember( 7, ReadConsistency::UpToDate )?->deactivationTimestamp );
	}

	public function testDeactivationLeavesOtherMembersActive(): void {
		$this->recordMember( userId: 7, email: 'jane@example.com', groupId: 3 );
		$this->recordMember( userId: 8, email: 'john@example.com', groupId: 3 );

		$this->members->deactivateMember( 7 );

		$this->assertTrue( $this->members->getMember( 8, ReadConsistency::UpToDate )?->isActive() );
	}

	public function testReactivatedMemberIsActiveAgain(): void {
		$this->recordMember( userId: 7, email: 'jane@example.com', groupId: 3 );
		$this->members->deactivateMember( 7 );

		$this->members->reactivateMember( 7 );

		$this->assertTrue( $this->members->getMember( 7, ReadConsistency::UpToDate )?->isActive() );
	}

	public function testTotalsCountEveryMemberAcrossGroups(): void {
		$this->recordMember( userId: 1, email: 'first@example.com', groupId: 1 );
		$this->recordMember( userId: 2, email: 'second@example.com', groupId: 1 );
		$this->recordMember( userId: 3, email: 'third@example.com', groupId: 2 );
		$this->members->deactivateMember( 2 );

		$totals = $this->members->getTotals();

		$this->assertSame( 3, $totals->overall->all );
		$this->assertSame( 2, $totals->overall->active );
	}

	public function testTotalsCountPerGroup(): void {
		$this->recordMember( userId: 1, email: 'first@example.com', groupId: 1 );
		$this->recordMember( userId: 2, email: 'second@example.com', groupId: 2 );
		$this->recordMember( userId: 3, email: 'third@example.com', groupId: 2 );
		$this->recordMember( userId: 4, email: 'fourth@example.com', groupId: 3 );
		$this->members->deactivateMember( 3 );

		$totals = $this->members->getTotals();

		$this->assertSame( 2, $totals->forGroup( 2 )->all );
		$this->assertSame( 1, $totals->forGroup( 2 )->active );
	}

	public function testGroupWithoutMembersCountsZero(): void {
		$this->recordMember( userId: 1, email: 'first@example.com', groupId: 1 );

		$this->assertSame( 0, $this->members->getTotals()->forGroup( 2 )->all );
	}

	public function testTotalsAreZeroWithoutMembers(): void {
		$totals = $this->members->getTotals();

		$this->assertSame( 0, $totals->overall->all );
		$this->assertSame( 0, $totals->overall->active );
		$this->assertSame( [], $totals->perGroup );
	}

	public function testMemberAdmittedWithoutAGroupHasNone(): void {
		$this->recordMemberWithoutAGroup( userId: 7, email: 'jane@example.com' );

		$this->assertNull( $this->members->getMember( 7, ReadConsistency::UpToDate )?->groupId );
	}

	public function testMembersWithoutAGroupCountInTheOverallTotals(): void {
		$this->recordMember( userId: 1, email: 'first@example.com', groupId: 1 );
		$this->recordMemberWithoutAGroup( userId: 2, email: 'second@example.com' );
		$this->recordMemberWithoutAGroup( userId: 3, email: 'third@example.com' );
		$this->members->deactivateMember( 3 );

		$totals = $this->members->getTotals();

		$this->assertSame( 3, $totals->overall->all );
		$this->assertSame( 2, $totals->overall->active );
	}

	public function testMembersWithoutAGroupAreCountedUnderNoGroup(): void {
		$this->recordMember( userId: 1, email: 'first@example.com', groupId: 1 );
		$this->recordMemberWithoutAGroup( userId: 2, email: 'second@example.com' );

		$totals = $this->members->getTotals();

		$this->assertSame( [ 1 ], array_keys( $totals->perGroup ) );
		$this->assertSame( 1, $totals->forGroup( 1 )->all );
	}

	public function testMemberWithoutAGroupGainsTheGroupTheyAreAttributedTo(): void {
		$this->recordMemberWithoutAGroup( userId: 7, email: 'jane@example.com' );

		$this->members->attributeToGroup( userId: 7, groupId: 3 );

		$this->assertSame( 3, $this->members->getMember( 7, ReadConsistency::UpToDate )?->groupId );
	}

	/**
	 * The group a member was admitted by is what the per-group counts are built on, and what
	 * keeps that group from being deleted. A later login may fill it in, never move it.
	 */
	public function testAttributionLeavesTheGroupAMemberAlreadyHas(): void {
		$this->recordMember( userId: 7, email: 'jane@example.com', groupId: 3 );

		$this->members->attributeToGroup( userId: 7, groupId: 5 );

		$this->assertSame( 3, $this->members->getMember( 7, ReadConsistency::UpToDate )?->groupId );
	}

	public function testAttributionLeavesOtherMembersWithoutAGroup(): void {
		$this->recordMemberWithoutAGroup( userId: 7, email: 'jane@example.com' );
		$this->recordMemberWithoutAGroup( userId: 8, email: 'john@example.com' );

		$this->members->attributeToGroup( userId: 7, groupId: 3 );

		$this->assertNull( $this->members->getMember( 8, ReadConsistency::UpToDate )?->groupId );
	}

	public function testMemberWithoutAGroupCanBeDeactivated(): void {
		$this->recordMemberWithoutAGroup( userId: 7, email: 'jane@example.com' );

		$this->members->deactivateMember( 7 );

		$this->assertFalse( $this->members->getMember( 7, ReadConsistency::UpToDate )?->isActive() );
	}

	public function testMemberWithoutAGroupCanBeReactivated(): void {
		$this->recordMemberWithoutAGroup( userId: 7, email: 'jane@example.com' );
		$this->members->deactivateMember( 7 );

		$this->members->reactivateMember( 7 );

		$this->assertTrue( $this->members->getMember( 7, ReadConsistency::UpToDate )?->isActive() );
	}

	private function recordMember( int $userId, string $email, int $groupId ): void {
		$this->members->recordMember(
			userId: $userId,
			email: $this->normalize( $email ),
			groupId: $groupId
		);
	}

	private function recordMemberWithoutAGroup( int $userId, string $email ): void {
		$this->members->recordMember(
			userId: $userId,
			email: $this->normalize( $email ),
			groupId: null
		);
	}

	private function findMember( string $email ): ?Member {
		return $this->members->findMemberByEmail( $this->normalize( $email ) );
	}

	private function normalize( string $email ): NormalizedEmail {
		$normalized = NormalizedEmail::fromString( $email );

		$this->assertNotNull( $normalized );

		return $normalized;
	}

}
