<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

enum DeleteGroupResult {

	case Deleted;
	case GroupNotFound;

	/**
	 * Members admitted through the group are still attributed to it, and the group is what says
	 * where they came from.
	 */
	case GroupHasMembers;

	case GroupNotEmpty;

}
