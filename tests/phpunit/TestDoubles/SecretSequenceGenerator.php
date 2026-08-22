<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use ProfessionalWiki\MemberAccess\Application\SecretGenerator;

/**
 * Hands out a different code and handle on every request, so that a test can tell the code issued
 * second from the one issued first.
 */
class SecretSequenceGenerator implements SecretGenerator {

	private int $codesGenerated = 0;
	private int $handlesGenerated = 0;

	/**
	 * @param string[] $codes
	 */
	public function __construct(
		private readonly array $codes
	) {
	}

	public function generateCode(): string {
		return $this->codes[$this->codesGenerated++] ?? end( $this->codes );
	}

	public function generateHandle(): string {
		return 'handle-' . ++$this->handlesGenerated;
	}

}
