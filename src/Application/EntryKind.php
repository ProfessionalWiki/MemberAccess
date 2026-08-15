<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

enum EntryKind: string {

	case Email = 'email';
	case Domain = 'domain';

}
