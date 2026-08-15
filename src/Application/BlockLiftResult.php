<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

enum BlockLiftResult {

	/**
	 * The account is no longer blocked, either because the extension's own block was lifted or
	 * because there was no block to begin with.
	 */
	case Lifted;

	/**
	 * A block placed for another reason is still on the account, and was left alone.
	 */
	case ForeignBlockKept;

	case Failed;

}
