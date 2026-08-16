<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

final class CreateGroupResult {

	private function __construct(
		public readonly CreateGroupOutcome $outcome,
		/**
		 * The new group. Set when the group was created.
		 */
		public readonly ?MemberGroup $group
	) {
	}

	public static function created( MemberGroup $group ): self {
		return new self( CreateGroupOutcome::Created, $group );
	}

	public static function invalidName(): self {
		return new self( CreateGroupOutcome::InvalidName, null );
	}

	public static function nameTooLong(): self {
		return new self( CreateGroupOutcome::NameTooLong, null );
	}

	public static function duplicateName(): self {
		return new self( CreateGroupOutcome::DuplicateName, null );
	}

}
