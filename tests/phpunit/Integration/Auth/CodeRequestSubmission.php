<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthManager;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\LoginCodeRequest;

/**
 * Builds a code request the way the login form does, so that what the tests hand to MediaWiki is
 * what a visitor pressing the button hands it, down to the fields the request keeps and discards.
 */
trait CodeRequestSubmission {

	/**
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
	 * What the login form submits through its first button, the password login's, which is where
	 * pressing Enter in any of its boxes sends it: every box, filled or not, and no code button.
	 * The requests are the ones the form is built from, so what they make of it is what is handed
	 * on, and a submission none of them claims comes back empty.
	 *
	 * @return AuthenticationRequest[]
	 */
	private function loginFormSubmission( string $username, string $password, string $address ): array {
		return AuthenticationRequest::loadRequestsFromSubmission(
			$this->getServiceContainer()->getAuthManager()->getAuthenticationRequests( AuthManager::ACTION_LOGIN ),
			[
				'username' => $username,
				'password' => $password,
				LoginCodeRequest::EMAIL_FIELD => $address
			]
		);
	}

}
