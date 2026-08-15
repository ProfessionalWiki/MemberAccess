<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * How current a read has to be. Stated rather than defaulted, because a decision made on a stale
 * row is a decision made wrongly, and only the caller knows whether that matters.
 */
enum ReadConsistency {

	case MayBeStale;

	/**
	 * Sees what was just written, at the cost of asking the primary database.
	 */
	case UpToDate;

}
