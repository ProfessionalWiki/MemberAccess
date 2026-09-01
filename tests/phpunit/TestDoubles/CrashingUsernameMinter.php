<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use ProfessionalWiki\MemberAccess\Application\UsernameMinter;
use Random\RandomException;

/**
 * A minter failing the way the platform fails rather than the way a minter refuses: random_int()
 * throws this when the random source is unavailable, and it is no RuntimeException.
 */
class CrashingUsernameMinter implements UsernameMinter {

	public function mintUsername(): string {
		throw new RandomException( 'The random source is unavailable here' );
	}

}
