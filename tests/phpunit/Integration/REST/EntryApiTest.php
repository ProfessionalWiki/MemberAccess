<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\REST;

use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\ResponseInterface;
use MediaWiki\Session\Session;
use MediaWiki\User\UserIdentityValue;
use ProfessionalWiki\MemberAccess\Application\AddEntriesUseCase;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\ListEntriesApi
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\AddEntriesApi
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\RemoveEntryApi
 */
class EntryApiTest extends RestApiTestCase {

	/**
	 * Valid values before and after each refused one, so that a batch stopping at the first refusal
	 * cannot pass. "Example.COM" is neither an address nor a domain, and "TAKEN@Example.com" is the
	 * one these tests have another group admit. The refused ones carry casing and spaces, so that
	 * naming them as they were stored rather than as they were sent cannot pass either.
	 */
	private const array MIXED_BATCH = [
		'anna@example.com',
		' Example.COM ',
		'ben@example.com',
		'TAKEN@Example.com ',
		'cara@example.com'
	];

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

		$this->assertSame( [ 'jane@example.com' ], $this->listedValues( $this->groupId ) );
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

		$this->assertSame( 'jane@example.com', $this->results( $response )[0]['entry']['value'] );
	}

	/**
	 * A caller matches results to what it sent, so a result names the value as it arrived rather
	 * than as it was stored.
	 */
	public function testResultNamesTheValueAsItWasSent(): void {
		$response = $this->add( '  JANE@Example.COM ' );

		$this->assertSame( '  JANE@Example.COM ', $this->results( $response )[0]['value'] );
	}

	public function testResultOfAnAddedValueNamesTheStoredEntry(): void {
		$entry = $this->results( $this->add( '@example.com' ) )[0]['entry'];

		$this->assertSame( $this->listEntries( $this->groupId )[0], $entry );
	}

	public function testEntriesOfOtherGroupsAreNotListed(): void {
		$other = $this->newGroup( 'Umbrella' );
		$this->add( 'jane@example.com' );
		$this->addTo( $other->id, 'john@example.com' );

		$this->assertSame( [ 'jane@example.com' ], $this->listedValues( $this->groupId ) );
		$this->assertSame( [ 'john@example.com' ], $this->listedValues( $other->id ) );
	}

	public function testEveryValueOfABatchIsAdded(): void {
		$this->add( 'jane@example.com', '@example.net', 'john@example.org' );

		$this->assertSame(
			[ '@example.net', 'jane@example.com', 'john@example.org' ],
			$this->listedValues( $this->groupId )
		);
	}

	public function testResultsComeInTheOrderTheValuesWereGiven(): void {
		$response = $this->add( 'third@example.com', 'first@example.com', 'second@example.com' );

		$this->assertSame(
			[ 'third@example.com', 'first@example.com', 'second@example.com' ],
			array_column( $this->results( $response ), 'value' )
		);
	}

	/**
	 * A refused value neither stops the batch nor voids what came before it.
	 */
	public function testValuesAroundARefusedOneAreStillAdded(): void {
		$this->admitElsewhere( 'taken@example.com' );

		$response = $this->add( ...self::MIXED_BATCH );

		$this->assertSame( [ true, false, true, false, true ], array_column( $this->results( $response ), 'added' ) );
		$this->assertSame(
			[ 'anna@example.com', 'ben@example.com', 'cara@example.com' ],
			$this->listedValues( $this->groupId )
		);
	}

	public function testRefusedValueSaysWhyItWasRefused(): void {
		$this->admitElsewhere( 'taken@example.com' );

		$errorCodes = array_column( $this->results( $this->add( ...self::MIXED_BATCH ) ), 'errorCode', 'value' );

		$this->assertSame(
			[ ' Example.COM ' => 'invalid_entry_value', 'TAKEN@Example.com ' => 'duplicate_entry' ],
			$errorCodes
		);
	}

	public function testValueThatDoesNotFitTheColumnIsRefused(): void {
		$this->assertValueRefused( 'entry_value_too_long', $this->add( '@' . str_repeat( 'a', 260 ) . '.com' ) );
	}

	public function testTextThatIsNoAddressOrDomainIsRefused(): void {
		$this->assertValueRefused( 'invalid_entry_value', $this->add( 'example.com' ) );
	}

	public function testValueAnotherGroupAdmitsIsRefused(): void {
		$this->add( 'jane@example.com' );

		$response = $this->addTo( $this->newGroup( 'Umbrella' )->id, 'jane@example.com' );

		$this->assertValueRefused( 'duplicate_entry', $response );
	}

	public function testDuplicateNamesTheGroupThatAlreadyAdmitsTheValue(): void {
		$this->add( 'jane@example.com' );

		$result = $this->results( $this->addTo( $this->newGroup( 'Umbrella' )->id, 'jane@example.com' ) )[0];

		$this->assertSame( $this->groupId, $result['conflictingGroupId'] );
		$this->assertSame( 'Acme', $result['conflictingGroupName'] );
	}

	/**
	 * Two spellings of one address are one value: what makes a duplicate is what is stored, not what
	 * was pasted.
	 */
	public function testSecondOccurrenceOfAValueInOneBatchIsRefused(): void {
		$response = $this->add( 'Jane@Example.com', ' jane@example.com ' );

		$this->assertSame( [ true, false ], array_column( $this->results( $response ), 'added' ) );
		$this->assertSame( [ 'jane@example.com' ], $this->listedValues( $this->groupId ) );
	}

	/**
	 * The group that already admits it is the one the batch itself put it in a moment earlier, which
	 * is not committed yet and so has to be looked up where it was written.
	 */
	public function testSecondOccurrenceNamesTheGroupTheBatchJustAddedItTo(): void {
		$results = $this->results( $this->add( 'jane@example.com', 'jane@example.com' ) );

		$this->assertSame( $this->groupId, $results[1]['conflictingGroupId'] );
		$this->assertSame( 'Acme', $results[1]['conflictingGroupName'] );
	}

	public function testBatchWithoutValuesAddsNothing(): void {
		$response = $this->runAddHandler( [ 'values' => [] ] );

		$this->assertSame( [], $this->results( $response ) );
		$this->assertSame( [], $this->listEntries( $this->groupId ) );
	}

	public function testBatchOverTheCapIsRefusedWholesale(): void {
		$response = $this->add( ...$this->generatedValues( AddEntriesUseCase::MAX_VALUES + 1 ) );

		$this->assertError( 'too_many_entry_values', 400, $response );
		$this->assertSame( [], $this->listEntries( $this->groupId ) );
	}

	public function testBodyWithoutAListOfValuesIsRefused(): void {
		$response = $this->runAddHandler( [ 'value' => 'jane@example.com' ] );

		$this->assertError( 'invalid_request_body', 400, $response );
	}

	public function testValuesGivenAsAJsonObjectAreRefused(): void {
		$response = $this->runAddHandler( [ 'values' => [ 'first' => 'jane@example.com' ] ] );

		$this->assertError( 'invalid_request_body', 400, $response );
	}

	public function testBodyWithAValueThatIsNoTextIsRefusedWholesale(): void {
		$response = $this->runAddHandler( [ 'values' => [ 'jane@example.com', 42 ] ] );

		$this->assertError( 'invalid_request_body', 400, $response );
		$this->assertSame( [], $this->listEntries( $this->groupId ) );
	}

	public function testAddingToAGroupThatIsNotThereIsRefused(): void {
		$this->assertError( 'group_not_found', 404, $this->addTo( 12345, 'jane@example.com' ) );
	}

	public function testAddingWithoutACsrfTokenAddsNothing(): void {
		$response = $this->runAddHandler(
			[ 'values' => [ 'jane@example.com' ] ],
			session: $this->getSession( false )
		);

		$this->assertError( 'invalid_csrf_token', 403, $response );
		$this->assertSame( [], $this->listEntries( $this->groupId ) );
	}

	public function testAddingByACallerWithoutTheRightAddsNothing(): void {
		$response = $this->runAddHandler( [ 'values' => [ 'jane@example.com' ] ], authority: $this->outsider() );

		$this->assertError( 'permission_denied', 403, $response );
		$this->assertSame( [], $this->listEntries( $this->groupId ) );
	}

	public function testListingAGroupThatIsNotThereIsRefused(): void {
		$response = $this->runHandler(
			MemberAccessExtension::newListEntriesApi(),
			$this->newRequest( 'GET', [], [ 'id' => '12345' ] )
		);

		$this->assertError( 'group_not_found', 404, $response );
	}

	public function testRemovedEntryIsNoLongerListed(): void {
		$entryId = $this->results( $this->add( 'jane@example.com' ) )[0]['entry']['id'];

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

		$response = $this->runAddHandler( [ 'values' => [ 'jane@example.com' ] ], authority: $admin );

		$entryId = (int)$this->results( $response )[0]['entry']['id'];
		$entry = MemberAccessExtension::getInstance()->newAllowlistRepository()->getEntry( $entryId );
		$actorId = $actors->findActorId( $admin->getUser(), $this->getDb() );

		$this->assertNotSame( $admin->getUser()->getId(), $actorId, 'the two ids must differ here' );
		$this->assertSame( $actorId, $entry?->actorId );
	}

	private function admitElsewhere( string $value ): void {
		$this->newEntry( $this->newGroup( 'Umbrella' )->id, $value );
	}

	/**
	 * @return string[]
	 */
	private function generatedValues( int $count ): array {
		return array_map(
			static fn ( int $number ): string => "member{$number}@example.com",
			range( 1, $count )
		);
	}

	/**
	 * @return string[]
	 */
	private function listedValues( int $groupId ): array {
		return array_column( $this->listEntries( $groupId ), 'value' );
	}

	private function add( string ...$values ): ResponseInterface {
		return $this->addTo( $this->groupId, ...$values );
	}

	private function addTo( int $groupId, string ...$values ): ResponseInterface {
		return $this->runAddHandler( [ 'values' => $values ], $groupId );
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function runAddHandler(
		array $body,
		?int $groupId = null,
		?Authority $authority = null,
		?Session $session = null
	): ResponseInterface {
		return $this->runHandler(
			MemberAccessExtension::newAddEntriesApi(),
			$this->newRequest( 'POST', $body, [ 'id' => (string)( $groupId ?? $this->groupId ) ] ),
			$authority,
			$session
		);
	}

	/**
	 * @return array<int, array<string, mixed>> One result per value, in the order they were given
	 */
	private function results( ResponseInterface $response ): array {
		$this->assertSame( 200, $response->getStatusCode() );

		return $this->bodyOf( $response )['results'];
	}

	private function assertValueRefused( string $errorCode, ResponseInterface $response ): void {
		$result = $this->results( $response )[0];

		$this->assertFalse( $result['added'] );
		$this->assertSame( $errorCode, $result['errorCode'] );
	}

	private function remove( int $entryId ): ResponseInterface {
		return $this->runHandler(
			MemberAccessExtension::newRemoveEntryApi(),
			$this->newRequest( 'DELETE', [], [ 'id' => (string)$entryId ] )
		);
	}

}
