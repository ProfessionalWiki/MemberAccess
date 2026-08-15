<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\Auth;

use MediaWiki\Auth\ButtonAuthenticationRequest;
use MediaWiki\Message\Message;

/**
 * The "email me a login code" button on the login form, with the address to send the code to.
 *
 * The address field is optional so that pressing another provider's button, which leaves it empty,
 * discards this request rather than failing the form on a field the visitor never meant to fill in.
 */
class LoginCodeRequest extends ButtonAuthenticationRequest {

	private const BUTTON_NAME = 'memberaccessLogin';
	public const EMAIL_FIELD = 'memberaccessEmail';

	public bool $memberaccessLogin = false;

	public string $memberaccessEmail = '';

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
				self::EMAIL_FIELD => [
					'type' => 'string',
					'label' => new Message( 'memberaccess-auth-email-label' ),
					'help' => new Message( 'memberaccess-auth-email-help' ),
					'optional' => true
				]
			],
			parent::getFieldInfo()
		);
	}

}
