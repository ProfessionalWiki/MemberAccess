<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * Deletes a group, refusing as long as it still holds allowlist entries, since deleting one would
 * silently revoke access for everyone those entries admit, and as long as members are attributed to
 * it, since the group is what records where they came from.
 *
 * Everything the refusals turn on is held while it is decided on, so that an entry added while this
 * runs is either seen and refuses the deletion, or arrives after it and finds no group to be added
 * to. An entry that outlived its group would admit nobody while still holding the only slot its
 * address has.
 *
 * A member can still arrive in the deleted group, from a login that matched the allowlist before
 * the deletion and recorded the attribution after it. Unlike an entry, such a row shows in the
 * roster, and removing the member clears it.
 */
class DeleteGroupUseCase {

	public function __construct(
		private readonly MemberGroupRepository $groups,
		private readonly AllowlistRepository $allowlist,
		private readonly MemberRepository $members
	) {
	}

	public function deleteGroup( int $groupId ): DeleteGroupResult {
		if ( $this->groups->lockGroup( $groupId ) === null ) {
			return DeleteGroupResult::GroupNotFound;
		}

		if ( $this->members->groupHasMembers( $groupId ) ) {
			return DeleteGroupResult::GroupHasMembers;
		}

		if ( $this->allowlist->groupHasEntries( $groupId ) ) {
			return DeleteGroupResult::GroupNotEmpty;
		}

		$this->groups->deleteGroup( $groupId );

		return DeleteGroupResult::Deleted;
	}

}
