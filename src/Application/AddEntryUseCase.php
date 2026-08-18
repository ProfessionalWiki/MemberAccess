<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * Adds one address or one whole domain to a group.
 *
 * The value has to parse as the same thing the login flow matches against, so that an entry always
 * admits what it looks like it admits. A value belongs to exactly one group, so adding one another
 * group already holds is refused rather than moved: that would silently change who is billed for
 * whoever it admits.
 *
 * The group is held for the length of the addition, so that a deletion running at the same time
 * either waits and then finds the entry, or goes first and leaves nothing to add the entry to. An
 * entry that outlived its group would admit nobody while still holding the only slot its address
 * has.
 */
class AddEntryUseCase {

	public function __construct(
		private readonly MemberGroupRepository $groups,
		private readonly AllowlistRepository $allowlist
	) {
	}

	public function addEntry( int $groupId, string $value, int $actorId ): AddEntryResult {
		$parsed = AllowlistValue::fromString( $value );

		if ( $parsed === null ) {
			return AllowlistValue::exceedsMaxLength( $value )
				? AddEntryResult::valueTooLong()
				: AddEntryResult::invalidValue();
		}

		if ( $this->groups->lockGroup( $groupId ) === null ) {
			return AddEntryResult::groupNotFound();
		}

		$entry = $this->allowlist->addEntry( $groupId, $parsed, $actorId );

		if ( $entry === null ) {
			return AddEntryResult::duplicateValue( $this->allowlist->findGroupForValue( $parsed ) );
		}

		return AddEntryResult::added( $entry );
	}

}
