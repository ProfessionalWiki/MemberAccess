<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

interface AllowlistRepository {

	/**
	 * @return ?AllowlistEntry Null when the value already belongs to a group
	 */
	public function addEntry( int $groupId, AllowlistValue $value, int $actorId ): ?AllowlistEntry;

	public function getEntry( int $entryId ): ?AllowlistEntry;

	public function removeEntry( int $entryId ): void;

	/**
	 * Writes down that an invitation just went to the entry's address, replacing what an earlier
	 * one wrote.
	 *
	 * @return string When it was recorded, as the entry now carries it
	 */
	public function recordInvitation( int $entryId ): string;

	/**
	 * @return AllowlistEntry[]
	 */
	public function listEntries( int $groupId ): array;

	public function countEntries( int $groupId ): int;

	/**
	 * Whether the group still holds an entry, read from the primary database with what it read
	 * held, so that an entry added while the answer is acted on is either seen or made to wait.
	 * A count read without holding anything answers from a snapshot that can predate the entry.
	 */
	public function groupHasEntries( int $groupId ): bool;

	public function findGroupForValue( AllowlistValue $value, ReadConsistency $consistency ): ?MemberGroup;

}
