<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\CreateGroupOutcome;
use ProfessionalWiki\MemberAccess\Application\CreateGroupUseCase;
use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryMemberGroupRepository;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\CreateGroupUseCase
 */
class CreateGroupUseCaseTest extends TestCase {

	private InMemoryMemberGroupRepository $groups;

	protected function setUp(): void {
		$this->groups = new InMemoryMemberGroupRepository();
	}

	public function testGroupIsCreated(): void {
		$result = $this->newUseCase()->createGroup( 'Acme' );

		$this->assertSame( CreateGroupOutcome::Created, $result->outcome );
		$this->assertSame( 'Acme', $result->group?->name );
	}

	public function testCreatedGroupIsInTheList(): void {
		$this->newUseCase()->createGroup( 'Acme' );

		$this->assertCount( 1, $this->groups->listGroups() );
	}

	public function testNameIsTrimmed(): void {
		$result = $this->newUseCase()->createGroup( '  Acme  ' );

		$this->assertSame( 'Acme', $result->group?->name );
	}

	public function testBlankNameIsRefused(): void {
		$result = $this->newUseCase()->createGroup( '   ' );

		$this->assertSame( CreateGroupOutcome::InvalidName, $result->outcome );
		$this->assertSame( [], $this->groups->listGroups() );
	}

	public function testNameOfExactlyTheLongestAcceptedLengthIsCreated(): void {
		$result = $this->newUseCase()->createGroup( str_repeat( 'a', MemberGroup::MAX_NAME_LENGTH ) );

		$this->assertSame( CreateGroupOutcome::Created, $result->outcome );
	}

	/**
	 * A name that does not fit is stored cut off, which makes it another group's name as far as
	 * the uniqueness check can tell, so the same name can be used twice.
	 */
	public function testNameOneByteTooLongIsRefused(): void {
		$result = $this->newUseCase()->createGroup( str_repeat( 'a', MemberGroup::MAX_NAME_LENGTH + 1 ) );

		$this->assertSame( CreateGroupOutcome::NameTooLong, $result->outcome );
		$this->assertSame( [], $this->groups->listGroups() );
	}

	/**
	 * The limit is the column's, which counts bytes, so a name of few enough characters can still
	 * be too long, and would be cut off through the middle of a character.
	 */
	public function testNameOfFewEnoughCharactersButTooManyBytesIsRefused(): void {
		$name = str_repeat( 'é', intdiv( MemberGroup::MAX_NAME_LENGTH, 2 ) + 1 );

		$this->assertSame( CreateGroupOutcome::NameTooLong, $this->newUseCase()->createGroup( $name )->outcome );
	}

	public function testNameThatIsAlreadyUsedIsRefused(): void {
		$this->groups->createGroup( 'Acme' );

		$result = $this->newUseCase()->createGroup( 'Acme' );

		$this->assertSame( CreateGroupOutcome::DuplicateName, $result->outcome );
		$this->assertCount( 1, $this->groups->listGroups() );
	}

	public function testNameThatDiffersOnlyInCaseIsRefused(): void {
		$this->groups->createGroup( 'Acme' );

		$this->assertSame(
			CreateGroupOutcome::DuplicateName,
			$this->newUseCase()->createGroup( 'ACME' )->outcome
		);
	}

	public function testNameUsedByAnotherGroupDoesNotBlockADifferentOne(): void {
		$this->groups->createGroup( 'Acme' );
		$this->groups->createGroup( 'Umbrella' );

		$this->assertSame(
			CreateGroupOutcome::Created,
			$this->newUseCase()->createGroup( 'Initech' )->outcome
		);
	}

	private function newUseCase(): CreateGroupUseCase {
		return new CreateGroupUseCase( groups: $this->groups );
	}

}
