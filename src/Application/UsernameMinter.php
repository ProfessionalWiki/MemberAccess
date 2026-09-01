<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

use Exception;

/**
 * Settles on the name a member's account is to be created under.
 */
interface UsernameMinter {

	/**
	 * A name no account holds and one may be created under.
	 *
	 * @throws Exception When no free name could be found or none could be drawn, which leaves the
	 * login refused rather than pointed at somebody else's account
	 */
	public function mintUsername(): string;

}
