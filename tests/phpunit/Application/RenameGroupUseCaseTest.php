<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\RenameGroupResult;
use ProfessionalWiki\MemberAccess\Application\RenameGroupUseCase;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryMemberGroupRepository;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\RenameGroupUseCase
 */
class RenameGroupUseCaseTest extends TestCase {

	private InMemoryMemberGroupRepository $groups;
	private int $groupId;

	protected function setUp(): void {
		$this->groups = new InMemoryMemberGroupRepository();
		$this->groupId = $this->groups->createGroup( 'Acme' )->id;
	}

	public function testGroupIsRenamed(): void {
		$result = $this->newUseCase()->renameGroup( $this->groupId, 'Acme Holding' );

		$this->assertSame( RenameGroupResult::Renamed, $result );
		$this->assertSame( 'Acme Holding', $this->groups->getGroup( $this->groupId )?->name );
	}

	public function testNameIsTrimmed(): void {
		$this->newUseCase()->renameGroup( $this->groupId, '  Acme Holding  ' );

		$this->assertSame( 'Acme Holding', $this->groups->getGroup( $this->groupId )?->name );
	}

	public function testBlankNameIsRefused(): void {
		$result = $this->newUseCase()->renameGroup( $this->groupId, '   ' );

		$this->assertSame( RenameGroupResult::InvalidName, $result );
		$this->assertSame( 'Acme', $this->groups->getGroup( $this->groupId )?->name );
	}

	public function testUnknownGroupIsRefused(): void {
		$result = $this->newUseCase()->renameGroup( $this->groupId + 100, 'Umbrella' );

		$this->assertSame( RenameGroupResult::GroupNotFound, $result );
	}

	public function testNameOfAnotherGroupIsRefused(): void {
		$this->groups->createGroup( 'Umbrella' );

		$result = $this->newUseCase()->renameGroup( $this->groupId, 'Umbrella' );

		$this->assertSame( RenameGroupResult::DuplicateName, $result );
		$this->assertSame( 'Acme', $this->groups->getGroup( $this->groupId )?->name );
	}

	public function testNameOfAnotherGroupDifferingOnlyInCaseIsRefused(): void {
		$this->groups->createGroup( 'Umbrella' );

		$this->assertSame(
			RenameGroupResult::DuplicateName,
			$this->newUseCase()->renameGroup( $this->groupId, 'umbrella' )
		);
	}

	public function testRecasingTheGroupsOwnNameIsAllowed(): void {
		$result = $this->newUseCase()->renameGroup( $this->groupId, 'ACME' );

		$this->assertSame( RenameGroupResult::Renamed, $result );
		$this->assertSame( 'ACME', $this->groups->getGroup( $this->groupId )?->name );
	}

	private function newUseCase(): RenameGroupUseCase {
		return new RenameGroupUseCase( groups: $this->groups );
	}

}
