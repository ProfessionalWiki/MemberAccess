<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\Auth;

use MediaWiki\Auth\ButtonAuthenticationRequest;
use MediaWiki\Message\Message;

/**
 * The "email me a login code" button on the login form, with the address to send the code to.
 *
 * The address has a box of its own rather than the form's username box, which labelled it
 * "Username": a field every provider describes is labelled by the first of them, and this is not it.
 *
 * So this request declares no username, and MediaWiki counts the attempt against the client IP
 * rather than against one address. Telling addresses apart is left to the extension's own throttle,
 * the tighter of the two. {@see \ProfessionalWiki\MemberAccess\Application\RequestThrottle}
 *
 * Declaring one anyway would answer for the username box as well, and two requests naming different
 * accounts is a conflict MediaWiki raises rather than resolves.
 *
 * The field is optional: required, it would be required of every login button on the form, since a
 * field is mandatory only where every provider asking for it says so. An empty box is refused when
 * this button is pressed instead.
 *
 * Pressing Enter in the box submits the form through its first button, which is the password
 * login's, so the request cannot wait for its own button: an address in the box asks for a code as
 * the button does. What a username and password beside the address mean is the provider's to say.
 * {@see MemberAuthenticationProvider::beginPrimaryAuthentication}
 */
class LoginCodeRequest extends ButtonAuthenticationRequest {

	public const BUTTON_NAME = 'memberaccessLogin';
	public const EMAIL_FIELD = 'memberaccessEmail';

	public bool $memberaccessLogin = false;
	public ?string $memberaccessEmail = null;

	public function __construct() {
		parent::__construct(
			self::BUTTON_NAME,
			new Message( 'memberaccess-auth-button-label' ),
			new Message( 'memberaccess-auth-button-help' ),
			// Required as a request, which keeps the username and password boxes optional for everyone:
			// a field is mandatory only where every required primary request has it.
			true
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function getFieldInfo() {
		$button = parent::getFieldInfo();
		// Loadable without the button pressed; whether it is a request is loadFromSubmission's to say.
		$button[self::BUTTON_NAME]['optional'] = true;

		return array_merge(
			[
				self::EMAIL_FIELD => [
					'type' => 'string',
					'label' => new Message( 'memberaccess-auth-email-label' ),
					'help' => new Message( 'memberaccess-auth-email-help' ),
					'optional' => true
				]
			],
			$button
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function loadFromSubmission( array $data ) {
		// Only text can be an address, and the API hands over whatever it was sent.
		if ( !is_string( $data[self::EMAIL_FIELD] ?? '' ) ) {
			return false;
		}

		if ( !parent::loadFromSubmission( $data ) ) {
			return false;
		}

		return $this->memberaccessLogin || $this->address() !== '';
	}

	/**
	 * Only surrounding space is removed, so that a mistyped address can be answered on as one and
	 * the code goes to the address that was asked for.
	 */
	public function address(): string {
		return is_string( $this->memberaccessEmail ) ? trim( $this->memberaccessEmail ) : '';
	}

}
