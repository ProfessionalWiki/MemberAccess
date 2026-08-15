<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

enum AddEntryOutcome {

	case Added;
	case InvalidValue;

	/**
	 * The value is longer than the column that stores it, which would silently cut it short.
	 */
	case ValueTooLong;

	case GroupNotFound;

	/**
	 * Some group already admits this address or domain. Which one is named in the result.
	 */
	case DuplicateValue;

}
