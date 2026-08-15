<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

enum CodeRequestOutcome {

	/**
	 * The request was taken. Whether a code was mailed is deliberately not disclosed.
	 */
	case Accepted;

	case Throttled;

	case InvalidEmail;

}
