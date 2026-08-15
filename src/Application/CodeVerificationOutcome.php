<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

enum CodeVerificationOutcome {

	case Pass;

	/**
	 * The code was wrong and attempts remain.
	 */
	case RetryableFailure;

	/**
	 * The code is gone: used, expired, out of attempts, or never issued. A new one has to be requested.
	 */
	case Burned;

}
