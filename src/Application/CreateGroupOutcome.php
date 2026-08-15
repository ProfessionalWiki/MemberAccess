<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

enum CreateGroupOutcome {

	case Created;
	case InvalidName;
	case DuplicateName;

}
