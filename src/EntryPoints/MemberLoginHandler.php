<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints;

use MediaWiki\Auth\Hook\UserLoggedInHook;
use MediaWiki\User\User;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use ProfessionalWiki\MemberAccess\Application\Schema;

/**
 * Records when a member last logged in.
 *
 * Hooks the login itself rather than either login route, so a one-time code and a single sign-on
 * login are recorded by the same mechanism. Accounts that are no members are left alone.
 */
class MemberLoginHandler implements UserLoggedInHook {

	public function __construct(
		private readonly MemberRepository $members,
		private readonly Schema $schema
	) {
	}

	/**
	 * This runs for every login on the wiki, so a wiki without a roster to record in has to be let
	 * through rather than have its logins fail.
	 *
	 * @param User $user
	 */
	public function onUserLoggedIn( $user ): void {
		if ( $this->schema->isMissing() ) {
			return;
		}

		$this->members->recordLogin( $user->getId() );
	}

}
