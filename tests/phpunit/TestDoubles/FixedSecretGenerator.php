<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use ProfessionalWiki\MemberAccess\Application\SecretGenerator;

class FixedSecretGenerator implements SecretGenerator {

	public function __construct(
		private readonly string $code = '12345678',
		private readonly string $handle = 'handle-1'
	) {
	}

	public function generateCode(): string {
		return $this->code;
	}

	public function generateHandle(): string {
		return $this->handle;
	}

}
