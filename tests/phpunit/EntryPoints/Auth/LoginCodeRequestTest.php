<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\EntryPoints\Auth;

use MediaWiki\Auth\AuthenticationRequest;
use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\LoginCodeRequest;

/**
 * What a submission has to carry to be a code request.
 *
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\LoginCodeRequest
 */
class LoginCodeRequestTest extends TestCase {

	/**
	 * Enter in the address box: the address arrives without the code button. {@see LoginCodeRequest}
	 */
	public function testAnAddressInItsBoxIsACodeRequestWithoutTheButton(): void {
		$requests = AuthenticationRequest::loadRequestsFromSubmission(
			[ new LoginCodeRequest() ],
			[ LoginCodeRequest::EMAIL_FIELD => 'jane@example.com' ]
		);

		$this->assertCount( 1, $requests );
		$this->assertSame( 'jane@example.com', $requests[0]->address() );
	}

	/**
	 * A password login leaves the address box empty, and must not carry a code request for the
	 * provider to answer on.
	 */
	public function testABlankBoxWithoutTheButtonIsNoCodeRequest(): void {
		$requests = AuthenticationRequest::loadRequestsFromSubmission(
			[ new LoginCodeRequest() ],
			[ LoginCodeRequest::EMAIL_FIELD => '   ' ]
		);

		$this->assertSame( [], $requests );
	}

	/**
	 * So that an empty box can be answered on.
	 */
	public function testTheButtonWithAnEmptyBoxIsACodeRequest(): void {
		$requests = AuthenticationRequest::loadRequestsFromSubmission(
			[ new LoginCodeRequest() ],
			[ LoginCodeRequest::EMAIL_FIELD => '', LoginCodeRequest::BUTTON_NAME => true ]
		);

		$this->assertCount( 1, $requests );
		$this->assertSame( '', $requests[0]->address() );
	}

	public function testAnAddressThatIsNotTextIsNoCodeRequest(): void {
		$requests = AuthenticationRequest::loadRequestsFromSubmission(
			[ new LoginCodeRequest() ],
			[ LoginCodeRequest::EMAIL_FIELD => [ 'jane@example.com' ] ]
		);

		$this->assertSame( [], $requests );
	}

	/**
	 * A submission that pressed some other button carries no code request, however filled in the
	 * username box it shares is.
	 */
	public function testAnotherProvidersButtonIsNoCodeRequest(): void {
		$requests = AuthenticationRequest::loadRequestsFromSubmission(
			[ new LoginCodeRequest() ],
			[ 'username' => 'jane@example.com' ]
		);

		$this->assertSame( [], $requests );
	}

}
