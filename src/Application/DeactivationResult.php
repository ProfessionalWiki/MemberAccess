<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

enum DeactivationResult {

	case Deactivated;

	/**
	 * The account was never admitted through the allowlist, so it is not ours to deactivate.
	 */
	case NotAMember;

	case BlockFailed;

}
