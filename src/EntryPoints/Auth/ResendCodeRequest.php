<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\Auth;

use MediaWiki\Auth\ButtonAuthenticationRequest;
use MediaWiki\Message\Message;

/**
 * The "send a new code" button on the screen asking for the code. It carries no address: the one
 * the code goes to is the one already asked for, held in the authentication session.
 *
 * A visitor whose allowance the throttle has spent is offered the button in name only. It says so
 * itself rather than through the session, since the login form is handed the requests and not the
 * session that made them. {@see \ProfessionalWiki\MemberAccess\EntryPoints\LoginFormHandler}
 */
class ResendCodeRequest extends ButtonAuthenticationRequest {

	public const BUTTON_NAME = 'memberaccessResend';

	public bool $memberaccessResend = false;

	public function __construct(
		private readonly bool $available = true
	) {
		parent::__construct(
			self::BUTTON_NAME,
			new Message( 'memberaccess-auth-resend-label' ),
			new Message( 'memberaccess-auth-resend-help' ),
			false
		);
	}

	public function isAvailable(): bool {
		return $this->available;
	}

}
