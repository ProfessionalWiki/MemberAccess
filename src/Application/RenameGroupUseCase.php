<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * Renames a group, refusing a name another group already has. Changing only the capitalisation of
 * a group's own name is a rename like any other.
 *
 * A name longer than the column is refused for the same reason: stored cut off, it is no longer the
 * name that was checked for being taken.
 */
class RenameGroupUseCase {

	public function __construct(
		private readonly MemberGroupRepository $groups
	) {
	}

	public function renameGroup( int $groupId, string $name ): RenameGroupResult {
		$name = trim( $name );

		if ( $name === '' ) {
			return RenameGroupResult::InvalidName;
		}

		if ( MemberGroup::nameExceedsMaxLength( $name ) ) {
			return RenameGroupResult::NameTooLong;
		}

		if ( $this->groups->getGroup( $groupId ) === null ) {
			return RenameGroupResult::GroupNotFound;
		}

		$holder = $this->groups->findGroupByName( $name );

		if ( $holder !== null && $holder->id !== $groupId ) {
			return RenameGroupResult::DuplicateName;
		}

		$this->groups->renameGroup( $groupId, $name );

		return RenameGroupResult::Renamed;
	}

}
