<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

enum RenameGroupResult {

	case Renamed;
	case InvalidName;
	case NameTooLong;
	case DuplicateName;
	case GroupNotFound;

}
