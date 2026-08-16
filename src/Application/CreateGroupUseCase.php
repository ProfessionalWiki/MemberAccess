<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * Creates a group, refusing a name another group already has. Two groups with the same name would
 * be indistinguishable everywhere they are chosen from, and attribution has to be unambiguous.
 *
 * A name longer than the column is refused for the same reason: stored cut off, it is no longer the
 * name that was checked for being taken.
 */
class CreateGroupUseCase {

	public function __construct(
		private readonly MemberGroupRepository $groups
	) {
	}

	public function createGroup( string $name ): CreateGroupResult {
		$name = trim( $name );

		if ( $name === '' ) {
			return CreateGroupResult::invalidName();
		}

		if ( MemberGroup::nameExceedsMaxLength( $name ) ) {
			return CreateGroupResult::nameTooLong();
		}

		if ( $this->groups->findGroupByName( $name ) !== null ) {
			return CreateGroupResult::duplicateName();
		}

		return CreateGroupResult::created( $this->groups->createGroup( $name ) );
	}

}
