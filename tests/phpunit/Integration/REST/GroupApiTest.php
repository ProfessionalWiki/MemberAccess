<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\REST;

use MediaWiki\Rest\ResponseInterface;
use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\ListGroupsApi
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\CreateGroupApi
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\RenameGroupApi
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\DeleteGroupApi
 */
class GroupApiTest extends RestApiTestCase {

	public function testListingIsEmptyWithoutGroups(): void {
		$this->assertSame( [], $this->listGroups() );
	}

	public function testCreatedGroupIsListed(): void {
		$this->create( 'Acme' );

		$this->assertSame( [ 'Acme' ], array_column( $this->listGroups(), 'name' ) );
	}

	public function testCreatedGroupIsReturnedWithItsId(): void {
		$response = $this->create( 'Acme' );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertIsInt( $this->bodyOf( $response )['id'] ?? null );
	}

	public function testListedGroupCarriesItsEntryAndMemberCounts(): void {
		$group = $this->newGroup( 'Acme' );
		$this->newEntry( $group->id, '@example.com' );
		$this->newEntry( $group->id, 'jane@example.org' );
		$this->newEntry( $group->id, 'jack@example.org' );
		$deactivated = $this->newMember( $group->id, 'jane@example.org' );
		$this->newMember( $group->id, 'john@example.com' );
		$this->deactivate( $deactivated );

		$listed = $this->listGroups()[0];

		$this->assertSame( 3, $listed['entryCount'] );
		$this->assertSame( 2, $listed['memberCount'] );
		$this->assertSame( 1, $listed['activeMemberCount'] );
	}

	public function testCountsOfOtherGroupsAreNotMixedIn(): void {
		$group = $this->newGroup( 'Acme' );
		$other = $this->newGroup( 'Umbrella' );
		$this->newEntry( $other->id, '@example.com' );
		$this->newEntry( $other->id, 'jane@example.org' );
		$this->newMember( $other->id, 'jane@example.org' );

		$listed = array_column( $this->listGroups(), null, 'id' )[$group->id];

		$this->assertSame( 0, $listed['entryCount'] );
		$this->assertSame( 0, $listed['memberCount'] );
	}

	public function testListedGroupCarriesItsCreationTime(): void {
		$this->newGroup( 'Acme' );

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T/',
			(string)$this->listGroups()[0]['created']
		);
	}

	public function testGroupWithoutANameIsRefused(): void {
		$this->assertError( 'invalid_group_name', 400, $this->create( '  ' ) );
	}

	public function testGroupWithANameThatDoesNotFitIsRefused(): void {
		$response = $this->create( str_repeat( 'a', MemberGroup::MAX_NAME_LENGTH + 1 ) );

		$this->assertError( 'group_name_too_long', 400, $response );
		$this->assertSame( [], $this->listGroups() );
	}

	public function testGroupWithAUsedNameIsRefused(): void {
		$this->newGroup( 'Acme' );

		$this->assertError( 'duplicate_group_name', 409, $this->create( 'acme' ) );
	}

	public function testGroupIsRenamed(): void {
		$group = $this->newGroup( 'Acme' );

		$response = $this->rename( $group->id, 'Acme Holding' );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( [ 'Acme Holding' ], array_column( $this->listGroups(), 'name' ) );
	}

	public function testRenamingToAUsedNameIsRefused(): void {
		$group = $this->newGroup( 'Acme' );
		$this->newGroup( 'Umbrella' );

		$this->assertError( 'duplicate_group_name', 409, $this->rename( $group->id, 'Umbrella' ) );
	}

	public function testRenamingWithoutANameIsRefused(): void {
		$group = $this->newGroup( 'Acme' );

		$this->assertError( 'invalid_group_name', 400, $this->rename( $group->id, '' ) );
	}

	public function testRenamingToANameThatDoesNotFitIsRefused(): void {
		$group = $this->newGroup( 'Acme' );

		$response = $this->rename( $group->id, str_repeat( 'a', MemberGroup::MAX_NAME_LENGTH + 1 ) );

		$this->assertError( 'group_name_too_long', 400, $response );
		$this->assertSame( [ 'Acme' ], array_column( $this->listGroups(), 'name' ) );
	}

	public function testRenamingAGroupThatIsNotThereIsRefused(): void {
		$this->assertError( 'group_not_found', 404, $this->rename( 12345, 'Umbrella' ) );
	}

	public function testEmptyGroupIsDeleted(): void {
		$group = $this->newGroup( 'Acme' );

		$response = $this->delete( $group->id );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( [], $this->listGroups() );
	}

	public function testGroupThatStillAdmitsPeopleIsNotDeleted(): void {
		$group = $this->newGroup( 'Acme' );
		$this->newEntry( $group->id, '@example.com' );

		$this->assertError( 'group_not_empty', 409, $this->delete( $group->id ) );
		$this->assertCount( 1, $this->listGroups() );
	}

	public function testGroupThatMembersAreAttributedToIsNotDeleted(): void {
		$group = $this->newGroup( 'Acme' );
		$this->newMember( $group->id, 'jane@example.com' );

		$this->assertError( 'group_has_members', 409, $this->delete( $group->id ) );
		$this->assertCount( 1, $this->listGroups() );
	}

	public function testDeletingAGroupThatIsNotThereIsRefused(): void {
		$this->assertError( 'group_not_found', 404, $this->delete( 12345 ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function listGroups(): array {
		$body = $this->bodyOf(
			$this->runHandler( MemberAccessExtension::newListGroupsApi(), $this->newRequest( 'GET' ) )
		);

		return $body['groups'];
	}

	private function create( string $name ): ResponseInterface {
		return $this->runHandler(
			MemberAccessExtension::newCreateGroupApi(),
			$this->newRequest( 'POST', [ 'name' => $name ] )
		);
	}

	private function rename( int $groupId, string $name ): ResponseInterface {
		return $this->runHandler(
			MemberAccessExtension::newRenameGroupApi(),
			$this->newRequest( 'PUT', [ 'name' => $name ], [ 'id' => (string)$groupId ] )
		);
	}

	private function delete( int $groupId ): ResponseInterface {
		return $this->runHandler(
			MemberAccessExtension::newDeleteGroupApi(),
			$this->newRequest( 'DELETE', [], [ 'id' => (string)$groupId ] )
		);
	}

	private function deactivate( int $userId ): void {
		MemberAccessExtension::getInstance()->newMemberRepository()->deactivateMember( $userId );
	}

}
