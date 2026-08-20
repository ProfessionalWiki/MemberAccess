<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * What became of one value of a batch. Whether the group itself is there is no part of this: that
 * question is answered once for the whole batch.
 */
enum AddEntryOutcome {

	case Added;
	case InvalidValue;

	/**
	 * The value is longer than the column that stores it, which would silently cut it short.
	 */
	case ValueTooLong;

	/**
	 * Some group already admits this address or domain. Which one is named in the result.
	 */
	case DuplicateValue;

}
