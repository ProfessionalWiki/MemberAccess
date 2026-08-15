<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

interface CodeMailer {

	public function sendCode( NormalizedEmail $email, string $code, int $expiryInMinutes ): void;

}
