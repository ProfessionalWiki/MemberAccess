<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\PasswordAuthenticationRequest;
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
	 * What pressing Enter in the address box submits: the form goes through its first button, the
	 * password login's, so the address arrives without the code button. Whatever the requests on the
	 * form make of that is what is handed on, so a submission nothing claims comes back empty.
	 *
	 * @return AuthenticationRequest[]
	 */
	private function addressSubmittedThroughThePasswordLoginButton( string $address ): array {
		return AuthenticationRequest::loadRequestsFromSubmission(
			[ new PasswordAuthenticationRequest(), new LoginCodeRequest() ],
			[
				'username' => '',
				'password' => '',
				LoginCodeRequest::EMAIL_FIELD => $address
			]
		);
	}

}
