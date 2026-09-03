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
 * the button does. A username and password filled in beside it are the password login they look
 * like, whoever filled them in, unless the button was pressed. An empty box without the button is a
 * password login too, which is none of this request's business; with the button it is answered on.
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
			// A required request of a primary provider, which is what keeps the username and password
			// boxes optional on the form: a field is mandatory only where every such request asks for
			// it, and this one asks for neither. Whether the button has to be pressed is another matter.
			true
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function getFieldInfo() {
		$button = parent::getFieldInfo();
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

		return parent::loadFromSubmission( $data )
			&& ( $this->memberaccessLogin || ( $this->address() !== '' && !self::carriesAPasswordLogin( $data ) ) );
	}

	/**
	 * Both boxes of core's password login, by the names its request gives them.
	 * {@see \MediaWiki\Auth\PasswordAuthenticationRequest}
	 *
	 * @param array<string, mixed> $data
	 */
	private static function carriesAPasswordLogin( array $data ): bool {
		return ( $data['username'] ?? '' ) !== '' && ( $data['password'] ?? '' ) !== '';
	}

	/**
	 * Only surrounding space is removed, so that a mistyped address can be answered on as one and
	 * the code goes to the address that was asked for.
	 */
	public function address(): string {
		return is_string( $this->memberaccessEmail ) ? trim( $this->memberaccessEmail ) : '';
	}

}
