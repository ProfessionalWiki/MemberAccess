<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * What became of a batch as a whole. Only a processed batch says anything about its values: the
 * other outcomes are decided before any value is looked at, and leave the group as it was.
 */
enum AddEntriesOutcome {

	case Processed;

	case GroupNotFound;

	/**
	 * More values than one batch may carry.
	 */
	case TooManyValues;

}
