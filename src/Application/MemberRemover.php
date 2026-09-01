<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

use Throwable;

/**
 * Forgets a member: the roster row goes and the account gives up their address and their sessions.
 *
 * The account itself stays, holding nothing of the member and no longer reachable by either login
 * route. The address is free again and reaches a new account at the next login, since the roster
 * is what joins an address to an account.
 */
interface MemberRemover {

	/**
	 * Either all of it happens or none does: a forgotten roster row whose account kept the address
	 * is a way back in, so it is never a state a removal leaves behind.
	 *
	 * @throws Throwable Whatever the writes throw, having left nothing of the removal behind
	 */
	public function removeMember( int $userId ): void;

}
