<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthManager;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\LoginCodeRequest;

/**
 * What the tests hand to MediaWiki as a login form submission, so that it is what a visitor's
 * browser hands it, down to the fields the requests keep and discard.
 */
trait CodeRequestSubmission {

	/**
	 * A code request alone, as pressing the button submits it. Built by hand rather than from the
	 * form's own requests, since some tests submit one while the route is off and the form has none.
	 *
	 * @return AuthenticationRequest[]
	 */
	private function submittedCodeRequest( string $address ): array {
		$requests = AuthenticationRequest::loadRequestsFromSubmission(
			[ new LoginCodeRequest() ],
			[
				LoginCodeRequest::EMAIL_FIELD => $address,
				LoginCodeRequest::BUTTON_NAME => true
			]
		);

		// A discarded request would leave the tests using this asking the provider nothing, and
		// passing for it.
		$this->assertCount( 1, $requests, 'the submission carried no code request' );

		return $requests;
	}

	/**
	 * What the login form submits, as the requests it is built from make of it: every box, and
	 * whether the code button was pressed. Not pressed, the submission went through the password
	 * login's button, which is where Enter sends it. {@see LoginCodeRequest}
	 *
	 * @return AuthenticationRequest[]
	 */
	private function loginFormSubmission(
		string $address,
		bool $codeButtonPressed,
		string $username = '',
		string $password = ''
	): array {
		return AuthenticationRequest::loadRequestsFromSubmission(
			$this->getServiceContainer()->getAuthManager()->getAuthenticationRequests( AuthManager::ACTION_LOGIN ),
			[
				'username' => $username,
				'password' => $password,
				LoginCodeRequest::EMAIL_FIELD => $address,
				LoginCodeRequest::BUTTON_NAME => $codeButtonPressed
			]
		);
	}

}
