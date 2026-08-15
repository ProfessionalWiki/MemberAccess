<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * Keeps a deactivated member out. Removing their allowlist entry would not: the account and its
 * session survive that, and only a block ends both.
 */
interface MemberBlocker {

	/**
	 * Blocks the account sitewide and indefinitely. An account that is already blocked, for
	 * whatever reason, is left with the block it has: it is locked out either way.
	 *
	 * @return bool False when the block could not be placed
	 */
	public function blockMember( int $userId, int $performerId ): bool;

	/**
	 * Lifts the block the extension placed. A block placed for another reason is left alone, and
	 * an account that is not blocked needs nothing done.
	 */
	public function unblockMember( int $userId, int $performerId ): BlockLiftResult;

}
