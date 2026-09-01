<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\REST;

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\ResponseInterface;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\ListMembersApi
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\DeactivateMemberApi
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\ReactivateMemberApi
 */
class MemberApiTest extends RestApiTestCase {

	private int $groupId;

	protected function setUp(): void {
		parent::setUp();

		$this->groupId = $this->newGroup( 'Acme' )->id;
	}

	public function testRosterIsEmptyWithoutMembers(): void {
		$this->assertSame( [], $this->roster()['members'] );
	}

	public function testMemberIsListedWithTheirAddressAndGroup(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );

		$member = $this->roster()['members'][0];

		$this->assertSame( $userId, $member['userId'] );
		$this->assertSame( 'jane@example.com', $member['email'] );
		$this->assertSame( $this->groupId, $member['groupId'] );
		$this->assertSame( 'Acme', $member['groupName'] );
	}

	public function testMemberWhoNeverLoggedInHasNoLastLogin(): void {
		$this->newMember( $this->groupId, 'jane@example.com' );

		$this->assertNull( $this->roster()['members'][0]['lastLogin'] );
	}

	public function testLastLoginIsListed(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );
		MemberAccessExtension::getInstance()->newMemberRepository()->recordLogin( $userId );

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T/',
			(string)$this->roster()['members'][0]['lastLogin']
		);
	}

	public function testTotalsCountMembersAndActiveMembers(): void {
		$this->newMember( $this->groupId, 'jane@example.com' );
		$doomed = $this->newMember( $this->groupId, 'john@example.com' );
		$this->deactivate( $doomed );

		$totals = $this->roster()['totals'];

		$this->assertSame( 2, $totals['all'] );
		$this->assertSame( 1, $totals['active'] );
	}

	public function testTotalsAreBrokenDownPerGroup(): void {
		$other = $this->newGroup( 'Umbrella' );
		$this->newMember( $this->groupId, 'jane@example.com' );
		$doomed = $this->newMember( $other->id, 'john@example.com' );
		$this->newMember( $other->id, 'jack@example.com' );
		$this->deactivate( $doomed );

		$perGroup = array_column( $this->roster()['totals']['perGroup'], null, 'groupId' );

		$this->assertSame( 2, $perGroup[$other->id]['all'] );
		$this->assertSame( 1, $perGroup[$other->id]['active'] );
		$this->assertSame( 'Umbrella', $perGroup[$other->id]['groupName'] );
	}

	public function testGroupWithoutMembersIsStillBrokenOut(): void {
		$perGroup = array_column( $this->roster()['totals']['perGroup'], null, 'groupId' );

		$this->assertSame( 0, $perGroup[$this->groupId]['all'] );
	}

	public function testMemberAdmittedWithoutAGroupIsListedWithoutOne(): void {
		$this->newMemberWithoutAGroup( 'jane@example.com' );

		$member = $this->roster()['members'][0];

		$this->assertNull( $member['groupId'] );
		$this->assertNull( $member['groupName'] );
	}

	public function testMembersWithoutAGroupCountInTheOverallTotals(): void {
		$this->newMember( $this->groupId, 'jane@example.com' );
		$this->newMemberWithoutAGroup( 'john@example.com' );

		$this->assertSame( 2, $this->roster()['totals']['all'] );
	}

	/**
	 * Two groups and a member outside both, so that a breakdown lumping the ungrouped member in
	 * with anybody shows up, whichever group it picks.
	 */
	public function testMembersWithoutAGroupAreLeftOutOfThePerGroupTotals(): void {
		$other = $this->newGroup( 'Umbrella' );
		$this->newMember( $this->groupId, 'jane@example.com' );
		$this->newMember( $other->id, 'john@example.com' );
		$this->newMember( $other->id, 'jack@example.com' );
		$this->newMemberWithoutAGroup( 'stranger@example.com' );

		$perGroup = array_column( $this->roster()['totals']['perGroup'], null, 'groupId' );

		$this->assertSame( 1, $perGroup[$this->groupId]['all'] );
		$this->assertSame( 2, $perGroup[$other->id]['all'] );
	}

	public function testMemberWithoutAGroupCanBeDeactivated(): void {
		$userId = $this->newMemberWithoutAGroup( 'jane@example.com' );

		$response = $this->deactivateThrough( $userId );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertFalse( $this->roster()['members'][0]['active'] );
	}

	public function testMemberWithoutAGroupCanBeReactivated(): void {
		$userId = $this->newMemberWithoutAGroup( 'jane@example.com' );
		$this->deactivateThrough( $userId );

		$response = $this->reactivateThrough( $userId );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertTrue( $this->roster()['members'][0]['active'] );
	}

	private function newMemberWithoutAGroup( string $email ): int {
		return $this->newMember( null, $email );
	}

	public function testDeactivatedMemberIsListedAsInactive(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );

		$response = $this->deactivateThrough( $userId );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertFalse( $this->roster()['members'][0]['active'] );
	}

	public function testDeactivatedMemberIsBlocked(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );

		$this->deactivateThrough( $userId );

		$this->assertNotNull( $this->blockOn( $userId ) );
	}

	public function testReactivatedMemberIsListedAsActive(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );
		$this->deactivateThrough( $userId );

		$response = $this->reactivateThrough( $userId );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertTrue( $this->roster()['members'][0]['active'] );
	}

	public function testReactivatedMemberIsNoLongerBlocked(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );
		$this->deactivateThrough( $userId );

		$response = $this->reactivateThrough( $userId );

		$this->assertNull( $this->blockOn( $userId ) );
		$this->assertFalse( $this->bodyOf( $response )['blocked'] );
	}

	public function testReactivationSaysSoWhenABlockPlacedByHandRemains(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );
		$this->blockByHand( $userId );

		$response = $this->reactivateThrough( $userId );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertTrue( $this->bodyOf( $response )['blocked'] );
	}

	public function testBlockPlacedByHandSurvivesAReactivation(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );
		$this->blockByHand( $userId );

		$this->reactivateThrough( $userId );

		$this->assertNotNull( $this->blockOn( $userId ) );
		$this->assertTrue( $this->roster()['members'][0]['active'] );
	}

	private function blockByHand( int $userId ): void {
		$this->blockByHandUntil( $userId, 'infinity' );
	}

	private function blockByHandUntil( int $userId, string $expiry ): void {
		$status = $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$this->getServiceContainer()->getUserFactory()->newFromId( $userId ),
			$this->getTestSysop()->getUser(),
			$expiry,
			'Spamming'
		)->placeBlock();

		$this->assertTrue( $status->isOK() );
	}

	/**
	 * Blocking yourself on a wiki where a block stops you logging in is a locked door with the key
	 * on the inside.
	 */
	public function testDeactivatingYourOwnAccountIsRefused(): void {
		$admin = $this->getTestSysop()->getUser();
		$this->recordAsMember( $admin->getId() );

		$this->assertError( 'cannot_deactivate_self', 409, $this->deactivateThrough( $admin->getId() ) );
		$this->assertNull( $this->blockOn( $admin->getId() ) );
	}

	public function testDeactivatingWithoutTheRightToBlockIsRefusedCleanly(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );

		$response = $this->runHandler(
			MemberAccessExtension::newDeactivateMemberApi(),
			$this->newRequest( 'POST', [], [ 'userId' => (string)$userId ] ),
			$this->managerWhoMayNotBlock()
		);

		$this->assertError( 'block_right_required', 403, $response );
	}

	/**
	 * The roster may never say a member is gone while they can still get in, so a deactivation that
	 * could not place its own block is a failure rather than a quiet no-op.
	 */
	public function testMemberCarryingABlockThatExpiresIsLeftActive(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );
		$this->blockByHandUntil( $userId, '7 days' );

		$this->assertError( 'block_failed', 500, $this->deactivateThrough( $userId ) );
		$this->assertTrue( $this->roster()['members'][0]['active'] );
	}

	public function testReactivatingWithoutTheRightToBlockIsRefusedCleanly(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );
		$this->deactivateThrough( $userId );

		$response = $this->runHandler(
			MemberAccessExtension::newReactivateMemberApi(),
			$this->newRequest( 'POST', [], [ 'userId' => (string)$userId ] ),
			$this->managerWhoMayNotBlock()
		);

		$this->assertError( 'block_right_required', 403, $response );
		$this->assertNotNull( $this->blockOn( $userId ) );
	}

	public function testDeactivatingAnAccountThatIsNoMemberIsRefused(): void {
		$outsider = $this->getMutableTestUser()->getUser();

		$this->assertError( 'not_a_member', 404, $this->deactivateThrough( $outsider->getId() ) );
		$this->assertNull( $this->blockOn( $outsider->getId() ) );
	}

	public function testReactivatingAnAccountThatIsNoMemberIsRefused(): void {
		$outsider = $this->getMutableTestUser()->getUser();

		$this->assertError( 'not_a_member', 404, $this->reactivateThrough( $outsider->getId() ) );
	}

	/**
	 * These endpoints take no body, and a browser posting none still announces a content type and
	 * a body of nothing, which is not a malformed request.
	 */
	public function testDeactivatingWithAnEmptyBodyIsAccepted(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );

		$response = $this->runHandler(
			MemberAccessExtension::newDeactivateMemberApi(),
			$this->emptyBodyRequest( [ 'userId' => (string)$userId ] )
		);

		$this->assertSame( 200, $response->getStatusCode() );
	}

	public function testReactivatingWithAnEmptyBodyIsAccepted(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );
		$this->deactivateThrough( $userId );

		$response = $this->runHandler(
			MemberAccessExtension::newReactivateMemberApi(),
			$this->emptyBodyRequest( [ 'userId' => (string)$userId ] )
		);

		$this->assertSame( 200, $response->getStatusCode() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function roster(): array {
		return $this->bodyOf(
			$this->runHandler( MemberAccessExtension::newListMembersApi(), $this->newRequest( 'GET' ) )
		);
	}

	private function deactivateThrough( int $userId ): ResponseInterface {
		return $this->runHandler(
			MemberAccessExtension::newDeactivateMemberApi(),
			$this->newRequest( 'POST', [], [ 'userId' => (string)$userId ] )
		);
	}

	private function reactivateThrough( int $userId ): ResponseInterface {
		return $this->runHandler(
			MemberAccessExtension::newReactivateMemberApi(),
			$this->newRequest( 'POST', [], [ 'userId' => (string)$userId ] )
		);
	}

	/**
	 * The right to manage members is granted on its own here, the way a wiki that hands it to
	 * someone other than its admins would.
	 */
	private function managerWhoMayNotBlock(): Authority {
		$this->setGroupPermissions( 'membermanager', 'memberaccess-manage', true );

		return $this->getMutableTestUser( [ 'membermanager' ] )->getAuthority();
	}

	private function recordAsMember( int $userId ): void {
		$email = NormalizedEmail::fromString( 'admin@example.com' );

		$this->assertNotNull( $email );

		MemberAccessExtension::getInstance()->newMemberRepository()
			->recordMember( userId: $userId, email: $email, groupId: $this->groupId );
	}

	private function deactivate( int $userId ): void {
		MemberAccessExtension::getInstance()->newMemberRepository()->deactivateMember( $userId );
	}

	private function blockOn( int $userId ): ?DatabaseBlock {
		return $this->getServiceContainer()->getDatabaseBlockStore()->newFromTarget(
			$this->getServiceContainer()->getUserFactory()->newFromId( $userId ),
			null,
			true
		);
	}

}
