<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * What became of an invitation. Only a sent one is written down: everything else leaves the entry
 * exactly as it was, so a caller can ask again once whatever stopped it is dealt with.
 */
enum InvitationOutcome {

	case Sent;

	case EntryNotFound;

	/**
	 * The entry admits a whole domain, which names nobody to write to.
	 */
	case NotAnAddress;

	/**
	 * The one-time code route is not offered, and the invitation says to log in with a code.
	 */
	case CodeLoginOff;

	case SendFailed;

}
