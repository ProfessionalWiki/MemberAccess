<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\REST;

use MediaWiki\Rest\ResponseInterface;
use MediaWiki\User\UserIdentityValue;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\ListEntriesApi
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\AddEntryApi
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\RemoveEntryApi
 */
class EntryApiTest extends RestApiTestCase {

	private int $groupId;

	protected function setUp(): void {
		parent::setUp();

		$this->groupId = $this->newGroup( 'Acme' )->id;
	}

	public function testGroupWithoutEntriesListsNone(): void {
		$this->assertSame( [], $this->listEntries( $this->groupId ) );
	}

	public function testAddedAddressIsListed(): void {
		$this->add( 'jane@example.com' );

		$this->assertSame( [ 'jane@example.com' ], array_column( $this->listEntries( $this->groupId ), 'value' ) );
	}

	public function testAddedAddressIsListedAsAnAddress(): void {
		$this->add( 'jane@example.com' );

		$this->assertSame( 'email', $this->listEntries( $this->groupId )[0]['kind'] );
	}

	public function testAddedDomainIsListedAsADomain(): void {
		$this->add( '@example.com' );

		$this->assertSame( 'domain', $this->listEntries( $this->groupId )[0]['kind'] );
	}

	public function testAddedValueIsNormalized(): void {
		$response = $this->add( '  JANE@Example.COM ' );

		$this->assertSame( 'jane@example.com', $this->bodyOf( $response )['value'] );
	}

	public function testEntriesOfOtherGroupsAreNotListed(): void {
		$other = $this->newGroup( 'Umbrella' );
		$this->add( 'jane@example.com' );
		$this->addTo( $other->id, 'john@example.com' );

		$this->assertSame( [ 'jane@example.com' ], array_column( $this->listEntries( $this->groupId ), 'value' ) );
		$this->assertSame( [ 'john@example.com' ], array_column( $this->listEntries( $other->id ), 'value' ) );
	}

	public function testTextThatIsNoAddressOrDomainIsRefused(): void {
		$this->assertError( 'invalid_entry_value', 400, $this->add( 'example.com' ) );
	}

	public function testValueThatDoesNotFitTheColumnIsRefused(): void {
		$this->assertError( 'entry_value_too_long', 400, $this->add( '@' . str_repeat( 'a', 260 ) . '.com' ) );
	}

	public function testAddingToAGroupThatIsNotThereIsRefused(): void {
		$this->assertError( 'group_not_found', 404, $this->addTo( 12345, 'jane@example.com' ) );
	}

	public function testListingAGroupThatIsNotThereIsRefused(): void {
		$response = $this->runHandler(
			MemberAccessExtension::newListEntriesApi(),
			$this->newRequest( 'GET', [], [ 'id' => '12345' ] )
		);

		$this->assertError( 'group_not_found', 404, $response );
	}

	public function testValueAnotherGroupAdmitsIsRefused(): void {
		$this->add( 'jane@example.com' );

		$response = $this->addTo( $this->newGroup( 'Umbrella' )->id, 'jane@example.com' );

		$this->assertError( 'duplicate_entry', 409, $response );
	}

	public function testDuplicateNamesTheGroupThatAlreadyAdmitsTheValue(): void {
		$this->add( 'jane@example.com' );

		$body = $this->bodyOf( $this->addTo( $this->newGroup( 'Umbrella' )->id, 'jane@example.com' ) );

		$this->assertSame( $this->groupId, $body['conflictingGroupId'] );
		$this->assertSame( 'Acme', $body['conflictingGroupName'] );
	}

	public function testRemovedEntryIsNoLongerListed(): void {
		$entryId = $this->bodyOf( $this->add( 'jane@example.com' ) )['id'];

		$response = $this->remove( (int)$entryId );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( [], $this->listEntries( $this->groupId ) );
	}

	public function testRemovingAnEntryThatIsNotThereIsRefused(): void {
		$this->assertError( 'entry_not_found', 404, $this->remove( 12345 ) );
	}

	/**
	 * Provenance is kept the way MediaWiki records who did something, as an actor id. The
	 * anonymous actor is acquired first so that the acting admin's actor id and user id cannot
	 * coincide, which would make either one look right.
	 */
	public function testAddedEntryRecordsTheActorWhoAddedIt(): void {
		$actors = $this->getServiceContainer()->getActorNormalization();
		$actors->acquireActorId( UserIdentityValue::newAnonymous( '198.51.100.7' ), $this->getDb() );
		$admin = $this->getMutableTestUser( [ 'sysop' ] )->getAuthority();

		$response = $this->runHandler(
			MemberAccessExtension::newAddEntryApi(),
			$this->newRequest( 'POST', [ 'value' => 'jane@example.com' ], [ 'id' => (string)$this->groupId ] ),
			$admin
		);

		$entryId = (int)$this->bodyOf( $response )['id'];
		$entry = MemberAccessExtension::getInstance()->newAllowlistRepository()->getEntry( $entryId );
		$actorId = $actors->findActorId( $admin->getUser(), $this->getDb() );

		$this->assertNotSame( $admin->getUser()->getId(), $actorId, 'the two ids must differ here' );
		$this->assertSame( $actorId, $entry?->actorId );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function listEntries( int $groupId ): array {
		$body = $this->bodyOf( $this->runHandler(
			MemberAccessExtension::newListEntriesApi(),
			$this->newRequest( 'GET', [], [ 'id' => (string)$groupId ] )
		) );

		return $body['entries'];
	}

	private function add( string $value ): ResponseInterface {
		return $this->addTo( $this->groupId, $value );
	}

	private function addTo( int $groupId, string $value ): ResponseInterface {
		return $this->runHandler(
			MemberAccessExtension::newAddEntryApi(),
			$this->newRequest( 'POST', [ 'value' => $value ], [ 'id' => (string)$groupId ] )
		);
	}

	private function remove( int $entryId ): ResponseInterface {
		return $this->runHandler(
			MemberAccessExtension::newRemoveEntryApi(),
			$this->newRequest( 'DELETE', [], [ 'id' => (string)$entryId ] )
		);
	}

}
