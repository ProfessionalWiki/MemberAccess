<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use ProfessionalWiki\MemberAccess\Application\UsernameGenerator;

/**
 * Hands out the given names in order, so that a test can decide what a name is up against: one an
 * account already holds, or one no account may be created under.
 */
class UsernameSequenceGenerator implements UsernameGenerator {

	private int $generated = 0;

	/**
	 * @param string[] $names
	 */
	public function __construct(
		private readonly array $names
	) {
	}

	/**
	 * The last name is handed out for good once the sequence runs out, so that a test can put a
	 * minter up against a name that never comes free.
	 */
	public function generateUsername(): string {
		return $this->names[$this->generated++] ?? $this->names[array_key_last( $this->names )];
	}

}
