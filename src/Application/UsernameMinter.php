<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

use RuntimeException;

/**
 * Settles on the name a member's account is to be created under.
 */
interface UsernameMinter {

	/**
	 * A name no account holds and one may be created under.
	 *
	 * @throws RuntimeException When no free name could be found, which leaves the login refused
	 * rather than pointed at somebody else's account
	 */
	public function mintUsername(): string;

}
