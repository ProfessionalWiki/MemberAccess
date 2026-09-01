<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

enum RemovalResult {

	case Removed;

	/**
	 * The account was never admitted through the allowlist, so it is not ours to remove.
	 */
	case NotAMember;

}
