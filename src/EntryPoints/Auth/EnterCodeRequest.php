<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\Auth;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Message\Message;

/**
 * The screen that asks for the code that was mailed. The code is checked against a handle held in
 * the authentication session, so nothing identifying travels through the form.
 */
class EnterCodeRequest extends AuthenticationRequest {

	public const CODE_FIELD = 'memberaccessCode';

	public string $memberaccessCode = '';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function getFieldInfo() {
		return [
			self::CODE_FIELD => [
				'type' => 'string',
				'label' => new Message( 'memberaccess-auth-code-label' ),
				'help' => new Message( 'memberaccess-auth-code-help' ),

				// The code logs its holder in, so core treats it like a password: it may only
				// arrive in a POST body, and is redacted from the action API's logs.
				'sensitive' => true,

				// Required, the box would have to be filled before the button asking for another
				// code could be pressed. An empty box is answered on instead.
				'optional' => true
			]
		];
	}

}
