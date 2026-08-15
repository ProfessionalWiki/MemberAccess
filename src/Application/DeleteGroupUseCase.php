<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * Deletes a group, refusing as long as it still holds allowlist entries, since deleting one would
 * silently revoke access for everyone those entries admit, and as long as members are attributed to
 * it, since the group is what records where they came from.
 */
class DeleteGroupUseCase {

	public function __construct(
		private readonly MemberGroupRepository $groups,
		private readonly AllowlistRepository $allowlist,
		private readonly MemberRepository $members
	) {
	}

	public function deleteGroup( int $groupId ): DeleteGroupResult {
		if ( $this->groups->getGroup( $groupId ) === null ) {
			return DeleteGroupResult::GroupNotFound;
		}

		if ( $this->members->getTotals()->forGroup( $groupId )->all > 0 ) {
			return DeleteGroupResult::GroupHasMembers;
		}

		if ( $this->allowlist->countEntries( $groupId ) > 0 ) {
			return DeleteGroupResult::GroupNotEmpty;
		}

		$this->groups->deleteGroup( $groupId );

		return DeleteGroupResult::Deleted;
	}

}
