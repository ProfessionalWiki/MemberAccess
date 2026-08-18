<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * Forgets a member: the roster row goes, the account gives up their address, and the username the
 * address maps to is freed by parking the account under a reserved name.
 *
 * Freeing the name is what a removal is for. A roster row deleted on its own would leave the
 * account holding the name the address maps to, which is where every later login with that address
 * arrives, so the address would be refused for good.
 */
interface MemberRemover {

	/**
	 * Either all of it happens or none does: an account holding a name that admits nobody is the
	 * state a removal exists to undo, so it is never one a removal leaves behind.
	 *
	 * @return RemovalResult Never NotAMember: whether the account is a member is the caller's to know
	 */
	public function removeMember( int $userId, int $performerId ): RemovalResult;

}
