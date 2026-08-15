<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints;

use MediaWiki\Auth\Hook\UserLoggedInHook;
use MediaWiki\User\User;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;

/**
 * Records when a member last logged in.
 *
 * Hooks the login itself rather than either login route, so a one-time code and a single sign-on
 * login are recorded by the same mechanism. Accounts that are no members are left alone.
 */
class MemberLoginHandler implements UserLoggedInHook {

	public function __construct(
		private readonly MemberRepository $members
	) {
	}

	/**
	 * @param User $user
	 */
	public function onUserLoggedIn( $user ): void {
		$this->members->recordLogin( $user->getId() );
	}

}
