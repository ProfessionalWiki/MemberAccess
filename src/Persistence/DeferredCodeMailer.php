<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use MediaWiki\Deferred\DeferredUpdates;
use ProfessionalWiki\MemberAccess\Application\CodeMailer;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;

/**
 * Hands the send off until after the response, so that how long a code request takes does not say
 * whether a mail went out, and with it whether the address is on the allowlist.
 */
class DeferredCodeMailer implements CodeMailer {

	public function __construct(
		private readonly CodeMailer $mailer
	) {
	}

	public function sendCode( NormalizedEmail $email, string $code, int $expiryInMinutes ): void {
		DeferredUpdates::addCallableUpdate(
			function () use ( $email, $code, $expiryInMinutes ): void {
				$this->mailer->sendCode( $email, $code, $expiryInMinutes );
			}
		);
	}

}
