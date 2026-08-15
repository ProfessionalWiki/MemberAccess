<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use ProfessionalWiki\MemberAccess\Persistence\DatabaseMemberGroupRepository;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\Persistence\DatabaseMemberGroupRepository
 */
class DatabaseMemberGroupRepositoryTest extends DatabaseRepositoryTestCase {

	public function testCreatedGroupCanBeRetrieved(): void {
		$repository = $this->newRepository();
		$repository->createGroup( 'Before' );
		$created = $repository->createGroup( 'Acme' );
		$repository->createGroup( 'After' );

		$this->assertEquals( $created, $repository->getGroup( $created->id ) );
	}

	public function testCreatedGroupKeepsItsName(): void {
		$this->assertSame( 'Acme', $this->newRepository()->createGroup( 'Acme' )->name );
	}

	public function testCreatedGroupIsTimestamped(): void {
		$repository = $this->newRepository();
		$created = $repository->createGroup( 'Acme' );

		$this->assertJustHappened( $repository->getGroup( $created->id )?->creationTimestamp );
	}

	public function testGroupsGetDistinctIds(): void {
		$repository = $this->newRepository();

		$this->assertNotSame(
			$repository->createGroup( 'First' )->id,
			$repository->createGroup( 'Second' )->id
		);
	}

	public function testUnknownGroupIsNotFound(): void {
		$repository = $this->newRepository();
		$repository->createGroup( 'Acme' );

		$this->assertNull( $repository->getGroup( 404 ) );
	}

	public function testRenamedGroupKeepsItsId(): void {
		$repository = $this->newRepository();
		$group = $repository->createGroup( 'Before' );

		$repository->renameGroup( $group->id, 'After' );

		$this->assertSame( 'After', $repository->getGroup( $group->id )?->name );
	}

	public function testRenamingLeavesOtherGroupsAlone(): void {
		$repository = $this->newRepository();
		$untouched = $repository->createGroup( 'Untouched' );

		$repository->renameGroup( $repository->createGroup( 'Before' )->id, 'After' );

		$this->assertSame( 'Untouched', $repository->getGroup( $untouched->id )?->name );
	}

	public function testDeletedGroupIsGone(): void {
		$repository = $this->newRepository();
		$group = $repository->createGroup( 'Doomed' );

		$repository->deleteGroup( $group->id );

		$this->assertNull( $repository->getGroup( $group->id ) );
	}

	public function testDeletingLeavesOtherGroupsAlone(): void {
		$repository = $this->newRepository();
		$survivor = $repository->createGroup( 'Survivor' );

		$repository->deleteGroup( $repository->createGroup( 'Doomed' )->id );

		$this->assertNotNull( $repository->getGroup( $survivor->id ) );
	}

	public function testListingReturnsEveryGroup(): void {
		$repository = $this->newRepository();
		$repository->createGroup( 'First' );
		$repository->createGroup( 'Second' );
		$repository->createGroup( 'Third' );

		$this->assertSame(
			[ 'First', 'Second', 'Third' ],
			array_map( static fn ( MemberGroup $group ): string => $group->name, $repository->listGroups() )
		);
	}

	public function testListingIsEmptyWithoutGroups(): void {
		$this->assertSame( [], $this->newRepository()->listGroups() );
	}

	public function testGroupIsFoundByItsName(): void {
		$repository = $this->newRepository();
		$repository->createGroup( 'First' );
		$wanted = $repository->createGroup( 'Second' );
		$repository->createGroup( 'Third' );

		$this->assertSame( $wanted->id, $repository->findGroupByName( 'Second' )?->id );
	}

	public function testGroupIsFoundWhateverTheCapitalisation(): void {
		$repository = $this->newRepository();
		$wanted = $repository->createGroup( 'Acme Holding' );

		$this->assertSame( $wanted->id, $repository->findGroupByName( 'aCME hOLDING' )?->id );
	}

	public function testNameNoGroupHasFindsNothing(): void {
		$repository = $this->newRepository();
		$repository->createGroup( 'Acme' );

		$this->assertNull( $repository->findGroupByName( 'Umbrella' ) );
	}

	private function newRepository(): DatabaseMemberGroupRepository {
		return new DatabaseMemberGroupRepository( connectionProvider: $this->newConnectionProvider() );
	}

}
