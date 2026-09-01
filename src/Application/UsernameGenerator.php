<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

interface UsernameGenerator {

	/**
	 * A candidate name for a member's account. Whether an account may be created under it is the
	 * minter's to settle. {@see UsernameMinter}
	 */
	public function generateUsername(): string;

}
