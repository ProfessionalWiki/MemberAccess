<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * A stash that cannot be counted in, the way a Redis or database backed one behaves while it is
 * unreachable.
 */
class UnavailableStash extends HashBagOStuff {

	/**
	 * @param string $key
	 * @param int $exptime
	 * @param int $step
	 * @param int|null $init
	 * @param int $flags
	 * @return false
	 */
	public function incrWithInit( $key, $exptime, $step = 1, $init = null, $flags = 0 ) {
		return false;
	}

}
