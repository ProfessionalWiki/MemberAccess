<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

enum ReactivationResult {

	case Reactivated;

	/**
	 * The member is active again, but a block placed for another reason is still keeping the
	 * account out, and only an admin can decide whether to lift it.
	 */
	case ReactivatedButStillBlocked;

	/**
	 * The account was never admitted through the allowlist, so it is not ours to reactivate.
	 */
	case NotAMember;

	case UnblockFailed;

}
