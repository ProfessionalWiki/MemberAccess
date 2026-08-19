<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * Adds addresses and whole domains to a group.
 *
 * Each value is dealt with on its own and in the order given, so that one refused value neither
 * stops the batch nor voids what came before it: an administrator pasting a list gets everything
 * that could be added, and is told about the rest one by one. A value that is already admitted is
 * refused rather than moved, whether another group already held it or an earlier value of this same
 * batch just added it: moving it would silently change who is billed for whoever it admits.
 *
 * A value has to parse as the same thing the login flow matches against, so that an entry always
 * admits what it looks like it admits.
 *
 * The group is held for the length of the batch, so that a deletion running at the same time either
 * waits and then finds the entries, or goes first and leaves nothing to add them to. An entry that
 * outlived its group would admit nobody while still holding the only slot its address has.
 */
class AddEntriesUseCase {

	/**
	 * The most one batch may carry, since the group is held for its whole length. Whoever has more
	 * to add asks more than once.
	 */
	public const int MAX_VALUES = 500;

	public function __construct(
		private readonly MemberGroupRepository $groups,
		private readonly AllowlistRepository $allowlist
	) {
	}

	/**
	 * @param string[] $values
	 */
	public function addEntries( int $groupId, array $values, int $actorId ): AddEntriesResult {
		if ( count( $values ) > self::MAX_VALUES ) {
			return AddEntriesResult::tooManyValues();
		}

		if ( $this->groups->lockGroup( $groupId ) === null ) {
			return AddEntriesResult::groupNotFound();
		}

		$results = [];

		foreach ( $values as $value ) {
			$results[] = $this->addEntry( $groupId, $value, $actorId );
		}

		return AddEntriesResult::processed( $results );
	}

	private function addEntry( int $groupId, string $value, int $actorId ): AddEntryResult {
		$parsed = AllowlistValue::fromString( $value );

		if ( $parsed === null ) {
			return AllowlistValue::exceedsMaxLength( $value )
				? AddEntryResult::valueTooLong( $value )
				: AddEntryResult::invalidValue( $value );
		}

		$entry = $this->allowlist->addEntry( $groupId, $parsed, $actorId );

		if ( $entry === null ) {
			// Up to date, because the group holding the value can be one an earlier value of this
			// same batch just went to, which a replica has not been told about.
			return AddEntryResult::duplicateValue(
				$value,
				$this->allowlist->findGroupForValue( $parsed, ReadConsistency::UpToDate )
			);
		}

		return AddEntryResult::added( $value, $entry );
	}

}
