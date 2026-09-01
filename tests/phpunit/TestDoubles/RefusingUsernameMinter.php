<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use ProfessionalWiki\MemberAccess\Application\UsernameMinter;
use RuntimeException;

/**
 * A minter that finds no free name, which is what a wiki whose accounts hold every name the
 * generator can draw is up against.
 */
class RefusingUsernameMinter implements UsernameMinter {

	public function mintUsername(): string {
		throw new RuntimeException( 'No free username could be minted for a member account' );
	}

}
