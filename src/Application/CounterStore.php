<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

interface CounterStore {

	/**
	 * Raises the counter by one, starting it with the given lifetime when it does not exist yet.
	 * Later increments do not extend that lifetime, so a counter covers a fixed window.
	 *
	 * @return ?int The new count, or null when the store could not count. Callers treat that as
	 *  reaching the limit: what nothing counts cannot be bounded.
	 */
	public function increment( string $key, int $ttlInSeconds ): ?int;

}
