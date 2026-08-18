<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use ProfessionalWiki\MemberAccess\Application\AllowlistEntry;
use ProfessionalWiki\MemberAccess\Application\AllowlistRepository;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use ProfessionalWiki\MemberAccess\Application\MemberGroupRepository;

class InMemoryAllowlistRepository implements AllowlistRepository {

	/**
	 * @var array<int, AllowlistEntry>
	 */
	private array $entries = [];

	/**
	 * @var array<int, AllowlistEntry> Entries the primary database holds that a replica has not seen
	 */
	private array $unreplicatedEntries = [];

	private int $nextId = 1;

	public function __construct(
		private readonly MemberGroupRepository $groups
	) {
	}

	public function addEntry( int $groupId, AllowlistValue $value, int $actorId ): ?AllowlistEntry {
		if ( $this->findEntry( $value ) !== null ) {
			return null;
		}

		$entry = new AllowlistEntry(
			id: $this->nextId++,
			groupId: $groupId,
			value: $value,
			actorId: $actorId,
			creationTimestamp: '20260101000000'
		);

		$this->entries[$entry->id] = $entry;

		return $entry;
	}

	public function getEntry( int $entryId ): ?AllowlistEntry {
		return $this->entries[$entryId] ?? null;
	}

	public function removeEntry( int $entryId ): void {
		unset( $this->entries[$entryId] );
	}

	/**
	 * Adds the entry the way the primary database holds it while a replica has not caught up, so
	 * that a stale read does not see it and an up to date one does.
	 */
	public function addEntryBehindTheReplica( int $groupId, AllowlistValue $value ): void {
		$id = $this->nextId++;

		$this->unreplicatedEntries[$id] = new AllowlistEntry(
			id: $id,
			groupId: $groupId,
			value: $value,
			actorId: 1,
			creationTimestamp: '20260101000000'
		);
	}

	public function listEntries( int $groupId ): array {
		return $this->entriesOfGroup( $this->entries, $groupId );
	}

	public function countEntries( int $groupId ): int {
		return count( $this->listEntries( $groupId ) );
	}

	public function groupHasEntries( int $groupId ): bool {
		$entries = $this->entries + $this->unreplicatedEntries;

		return $this->entriesOfGroup( $entries, $groupId ) !== [];
	}

	/**
	 * @param AllowlistEntry[] $entries
	 * @return AllowlistEntry[]
	 */
	private function entriesOfGroup( array $entries, int $groupId ): array {
		return array_values(
			array_filter(
				$entries,
				static fn ( AllowlistEntry $entry ): bool => $entry->groupId === $groupId
			)
		);
	}

	public function findGroupForValue( AllowlistValue $value ): ?MemberGroup {
		$entry = $this->findEntry( $value );

		return $entry === null ? null : $this->groups->getGroup( $entry->groupId );
	}

	private function findEntry( AllowlistValue $value ): ?AllowlistEntry {
		foreach ( $this->entries as $entry ) {
			if ( $entry->value->value === $value->value ) {
				return $entry;
			}
		}

		return null;
	}

}
