<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\Auth;

use MediaWiki\Auth\ButtonAuthenticationRequest;
use MediaWiki\Message\Message;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;

/**
 * The "email me a login code" button on the login form, with the address to send the code to.
 *
 * The address travels in the username field the login form already has, because a member's address
 * is their username here. That keeps one identity box on the form however the visitor goes on to
 * prove it, and it is how MediaWiki learns whose login this is: it looks for a request declaring a
 * username, and counts the attempt against that account's throttle rather than against one bucket
 * shared by everyone at the same client IP.
 *
 * The field is optional. Marked required it would be required of the whole login form, since a
 * shared field is mandatory only where every provider asking for it says so, and the box would then
 * have to be filled before any other login button could be pressed. An empty box is refused when
 * this button is pressed instead.
 */
class LoginCodeRequest extends ButtonAuthenticationRequest {

	public const BUTTON_NAME = 'memberaccessLogin';

	public bool $memberaccessLogin = false;

	private string $address = '';

	public function __construct() {
		parent::__construct(
			self::BUTTON_NAME,
			new Message( 'memberaccess-auth-button-label' ),
			new Message( 'memberaccess-auth-button-help' ),
			true
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function getFieldInfo() {
		return array_merge(
			[
				'username' => [
					'type' => 'string',
					'label' => new Message( 'memberaccess-auth-email-label' ),
					'help' => new Message( 'memberaccess-auth-email-help' ),
					'optional' => true
				]
			],
			parent::getFieldInfo()
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function loadFromSubmission( array $data ): bool {
		if ( !parent::loadFromSubmission( $data ) ) {
			return false;
		}

		$this->address = is_string( $this->username ) ? trim( $this->username ) : '';

		// A box holding no address names nobody. Left as submitted it would still read as an answer,
		// which MediaWiki normalises to nothing and counts against no throttle at all; emptied, the
		// attempt is counted against the client IP instead. What was typed is still answered on, so
		// that a mistyped address can be named as one.
		//
		// An address is passed on exactly as submitted, since a password request reading the same
		// box has to arrive at the same string or MediaWiki sees two conflicting logins. That leaves
		// an address MediaWiki cannot turn into a username, one holding a "#" for instance, counted
		// against nothing here; the extension's own per-address and per-IP limits, tighter than
		// core's, are what bound those.
		if ( NormalizedEmail::fromString( $this->address ) === null ) {
			$this->username = null;
		}

		return true;
	}

	public function address(): string {
		return $this->address;
	}

}
