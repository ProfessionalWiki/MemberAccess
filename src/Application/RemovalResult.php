<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

enum RemovalResult {

	case Removed;

	/**
	 * The account was never admitted through the allowlist, so it is not ours to remove.
	 */
	case NotAMember;

	/**
	 * The name a removed member's account is parked under is held by another account, which
	 * removing this member would have to rename away first.
	 */
	case ReservedNameTaken;

	/**
	 * The account could not be renamed, so the member is left as they were rather than left
	 * holding a username that admits nobody.
	 */
	case RemovalFailed;

}
