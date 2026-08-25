<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\REST;

use ProfessionalWiki\MemberAccess\MemberAccessExtension;

/**
 * The gate every endpoint sits behind, exercised through two of them.
 *
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\MemberAccessApiHandler
 */
class MemberAccessApiHandlerTest extends RestApiTestCase {

	public function testAnonymousCallerIsToldToLogIn(): void {
		$response = $this->runHandler(
			MemberAccessExtension::newListGroupsApi(),
			$this->newRequest( 'GET' ),
			$this->anon()
		);

		$this->assertError( 'not_logged_in', 401, $response );
	}

	public function testCallerWithoutTheRightIsRefused(): void {
		$response = $this->runHandler(
			MemberAccessExtension::newListGroupsApi(),
			$this->newRequest( 'GET' ),
			$this->outsider()
		);

		$this->assertError( 'permission_denied', 403, $response );
	}

	public function testCallerWithTheRightIsLetIn(): void {
		$response = $this->runHandler(
			MemberAccessExtension::newListGroupsApi(),
			$this->newRequest( 'GET' )
		);

		$this->assertSame( 200, $response->getStatusCode() );
	}

	public function testWriteWithoutACsrfTokenIsRefused(): void {
		$response = $this->runHandler(
			MemberAccessExtension::newCreateGroupApi(),
			$this->newRequest( 'POST', [ 'name' => 'Acme' ] ),
			null,
			$this->getSession( false )
		);

		$this->assertError( 'invalid_csrf_token', 403, $response );
	}

	public function testWriteWithAWrongCsrfTokenIsRefused(): void {
		$response = $this->runHandler(
			MemberAccessExtension::newCreateGroupApi(),
			$this->newRequest( 'POST', [ 'name' => 'Acme' ], [], [ 'X-CSRF-TOKEN' => 'not-the-token' ] ),
			null,
			$this->getSession( false )
		);

		$this->assertError( 'invalid_csrf_token', 403, $response );
	}

	public function testWriteOverASessionThatCannotBeForgedNeedsNoToken(): void {
		$response = $this->runHandler(
			MemberAccessExtension::newCreateGroupApi(),
			$this->newRequest( 'POST', [ 'name' => 'Acme' ] ),
			null,
			$this->getSession( true )
		);

		$this->assertSame( 200, $response->getStatusCode() );
	}

	public function testReadOverAForgeableSessionNeedsNoToken(): void {
		$response = $this->runHandler(
			MemberAccessExtension::newListGroupsApi(),
			$this->newRequest( 'GET' ),
			null,
			$this->getSession( false )
		);

		$this->assertSame( 200, $response->getStatusCode() );
	}

	public function testBodyWithoutTheExpectedFieldIsTreatedAsEmpty(): void {
		$response = $this->runHandler(
			MemberAccessExtension::newCreateGroupApi(),
			$this->newRequest( 'POST', [ 'nickname' => 'Acme' ] )
		);

		$this->assertError( 'invalid_group_name', 400, $response );
	}

}
