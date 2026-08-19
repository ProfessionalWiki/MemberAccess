<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\AddEntriesOutcome;
use ProfessionalWiki\MemberAccess\Application\AddEntriesResult;
use ProfessionalWiki\MemberAccess\Application\AddEntriesUseCase;
use ProfessionalWiki\MemberAccess\Application\AddEntryOutcome;
use ProfessionalWiki\MemberAccess\Application\AddEntryResult;
use ProfessionalWiki\MemberAccess\Application\AllowlistEntry;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\EntryKind;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryAllowlistRepository;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryMemberGroupRepository;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\AddEntriesUseCase
 */
class AddEntriesUseCaseTest extends TestCase {

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
		$result = $this->addEntry( '@' . str_repeat( 'a', 260 ) . '.com' );

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
		$this->assertSame( [], $this->addedValues() );
	}

	public function testBareAtSignIsRefused(): void {
		$this->assertSame( AddEntryOutcome::InvalidValue, $this->addEntry( '@' )->outcome );
	}

	public function testUnknownGroupTakesNoEntries(): void {
		$result = $this->newUseCase()->addEntries(
			$this->groupId + 100,
			[ 'jane@example.com' ],
			self::ACTOR_ID
		);

		$this->assertSame( AddEntriesOutcome::GroupNotFound, $result->outcome );
		$this->assertSame( [], $result->results );
	}

	/**
	 * A group deleted while entries are being added has not gone from a replica yet, and an entry
	 * that outlived its group admits nobody while still holding the only slot its address has.
	 */
	public function testGroupThatIsAlreadyGoneOnThePrimaryTakesNoEntries(): void {
		$this->groups->deleteGroupBehindTheReplica( $this->groupId );

		$result = $this->addEntries( 'jane@example.com' );

		$this->assertSame( AddEntriesOutcome::GroupNotFound, $result->outcome );
		$this->assertSame( [], $this->addedValues() );
	}

	public function testValueThatIsAlreadyInTheSameGroupIsRefused(): void {
		$this->addEntry( 'jane@example.com' );

		$result = $this->addEntry( 'jane@example.com' );

		$this->assertSame( AddEntryOutcome::DuplicateValue, $result->outcome );
		$this->assertSame( [ 'jane@example.com' ], $this->addedValues() );
	}

	public function testDuplicateNamesTheGroupThatAlreadyHasTheValue(): void {
		$this->addEntry( 'jane@example.com' );
		$other = $this->groups->createGroup( 'Umbrella' );

		$result = $this->newUseCase()->addEntries( $other->id, [ 'jane@example.com' ], self::ACTOR_ID );

		$this->assertSame( AddEntryOutcome::DuplicateValue, $result->results[0]->outcome );
		$this->assertSame( 'Acme', $result->results[0]->conflictingGroup?->name );
	}

	public function testEveryValueOfABatchIsAdded(): void {
		$result = $this->addEntries( 'jane@example.com', '@example.net', 'john@example.org' );

		$this->assertSame(
			[ AddEntryOutcome::Added, AddEntryOutcome::Added, AddEntryOutcome::Added ],
			$this->outcomesOf( $result )
		);
		$this->assertSame( [ 'jane@example.com', '@example.net', 'john@example.org' ], $this->addedValues() );
	}

	/**
	 * A refused value neither stops the batch nor voids what came before it.
	 */
	public function testValuesAroundARefusedOneAreStillAdded(): void {
		$result = $this->addEntries( 'first@example.com', 'example.com', 'last@example.com' );

		$this->assertSame(
			[ AddEntryOutcome::Added, AddEntryOutcome::InvalidValue, AddEntryOutcome::Added ],
			$this->outcomesOf( $result )
		);
		$this->assertSame( [ 'first@example.com', 'last@example.com' ], $this->addedValues() );
	}

	public function testSecondOccurrenceOfAValueInOneBatchIsRefused(): void {
		$result = $this->addEntries( 'jane@example.com', 'jane@example.com' );

		$this->assertSame(
			[ AddEntryOutcome::Added, AddEntryOutcome::DuplicateValue ],
			$this->outcomesOf( $result )
		);
		$this->assertSame( [ 'jane@example.com' ], $this->addedValues() );
	}

	public function testSecondOccurrenceNamesTheGroupItWasJustAddedTo(): void {
		$result = $this->addEntries( 'jane@example.com', 'jane@example.com' );

		$this->assertSame( 'Acme', $result->results[1]->conflictingGroup?->name );
	}

	/**
	 * The entry holding the value can be one a replica has not been told about, the way the entry an
	 * earlier value of the same batch just created is, so the group is looked up where it was written.
	 */
	public function testDuplicateOfAnEntryOnlyThePrimaryHasNamesItsGroup(): void {
		$other = $this->groups->createGroup( 'Umbrella' );
		$this->allowlist->addEntryBehindTheReplica( $other->id, $this->value( 'jane@example.com' ) );

		$result = $this->addEntry( 'jane@example.com' );

		$this->assertSame( AddEntryOutcome::DuplicateValue, $result->outcome );
		$this->assertSame( 'Umbrella', $result->conflictingGroup?->name );
	}

	public function testBatchWithoutValuesAddsNothing(): void {
		$result = $this->addEntries();

		$this->assertSame( AddEntriesOutcome::Processed, $result->outcome );
		$this->assertSame( [], $result->results );
	}

	public function testBatchAtTheCapIsProcessed(): void {
		$result = $this->addEntries( ...$this->generatedValues( AddEntriesUseCase::MAX_VALUES ) );

		$this->assertSame( AddEntriesOutcome::Processed, $result->outcome );
		$this->assertCount( AddEntriesUseCase::MAX_VALUES, $result->results );
	}

	public function testBatchOverTheCapAddsNothing(): void {
		$result = $this->addEntries( ...$this->generatedValues( AddEntriesUseCase::MAX_VALUES + 1 ) );

		$this->assertSame( AddEntriesOutcome::TooManyValues, $result->outcome );
		$this->assertSame( [], $this->addedValues() );
	}

	private function addEntry( string $value ): AddEntryResult {
		return $this->addEntries( $value )->results[0];
	}

	private function addEntries( string ...$values ): AddEntriesResult {
		return $this->newUseCase()->addEntries( $this->groupId, $values, self::ACTOR_ID );
	}

	private function newUseCase(): AddEntriesUseCase {
		return new AddEntriesUseCase( groups: $this->groups, allowlist: $this->allowlist );
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

	private function value( string $value ): AllowlistValue {
		$parsed = AllowlistValue::fromString( $value );

		$this->assertNotNull( $parsed );

		return $parsed;
	}

	/**
	 * @return AddEntryOutcome[]
	 */
	private function outcomesOf( AddEntriesResult $result ): array {
		return array_map(
			static fn ( AddEntryResult $entryResult ): AddEntryOutcome => $entryResult->outcome,
			$result->results
		);
	}

	/**
	 * @return string[] What the group holds, as the in-memory repository kept it: in the order the
	 *   values reached it, which is what makes the order of processing visible here
	 */
	private function addedValues(): array {
		return array_map(
			static fn ( AllowlistEntry $entry ): string => $entry->value->value,
			$this->allowlist->listEntries( $this->groupId )
		);
	}

}
