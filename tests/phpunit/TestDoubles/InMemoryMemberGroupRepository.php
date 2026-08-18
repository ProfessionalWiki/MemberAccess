<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use ProfessionalWiki\MemberAccess\Application\MemberGroupRepository;

class InMemoryMemberGroupRepository implements MemberGroupRepository {

	/**
	 * @var array<int, MemberGroup>
	 */
	private array $groups = [];

	/**
	 * @var array<int, MemberGroup> Groups gone from the primary that a replica still shows
	 */
	private array $deletedBehindTheReplica = [];

	private int $nextId = 1;

	public function createGroup( string $name ): MemberGroup {
		$group = new MemberGroup(
			id: $this->nextId++,
			name: $name,
			creationTimestamp: '20260101000000'
		);

		$this->groups[$group->id] = $group;

		return $group;
	}

	public function renameGroup( int $groupId, string $name ): void {
		$group = $this->groups[$groupId] ?? null;

		if ( $group !== null ) {
			$this->groups[$groupId] = new MemberGroup(
				id: $group->id,
				name: $name,
				creationTimestamp: $group->creationTimestamp
			);
		}
	}

	public function deleteGroup( int $groupId ): void {
		unset( $this->groups[$groupId] );
	}

	/**
	 * Deletes the group the way the primary database has it while a replica has not caught up, so
	 * that a stale read still finds it and an up to date one does not.
	 */
	public function deleteGroupBehindTheReplica( int $groupId ): void {
		$group = $this->groups[$groupId] ?? null;

		if ( $group !== null ) {
			$this->deletedBehindTheReplica[$groupId] = $group;
			$this->deleteGroup( $groupId );
		}
	}

	public function getGroup( int $groupId ): ?MemberGroup {
		return $this->groups[$groupId] ?? $this->deletedBehindTheReplica[$groupId] ?? null;
	}

	public function lockGroup( int $groupId ): ?MemberGroup {
		return $this->groups[$groupId] ?? null;
	}

	public function findGroupByName( string $name ): ?MemberGroup {
		foreach ( $this->groups as $group ) {
			if ( mb_strtolower( $group->name ) === mb_strtolower( $name ) ) {
				return $group;
			}
		}

		return null;
	}

	public function listGroups(): array {
		return array_values( $this->groups );
	}

}
