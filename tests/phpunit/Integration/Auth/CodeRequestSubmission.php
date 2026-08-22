<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use MediaWiki\Auth\AuthenticationRequest;
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

}
