<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\CreateGroupOutcome;
use ProfessionalWiki\MemberAccess\Application\CreateGroupUseCase;
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
