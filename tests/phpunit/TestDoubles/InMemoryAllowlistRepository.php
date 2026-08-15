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

	public function listEntries( int $groupId ): array {
		return array_values(
			array_filter(
				$this->entries,
				static fn ( AllowlistEntry $entry ): bool => $entry->groupId === $groupId
			)
		);
	}

	public function countEntries( int $groupId ): int {
		return count( $this->listEntries( $groupId ) );
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
