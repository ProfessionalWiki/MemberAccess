<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\REST;

use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\MissingSchema;

/**
 * The gate every endpoint sits behind, exercised through two of them.
 *
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\MemberAccessApiHandler
 */
class MemberAccessApiHandlerTest extends RestApiTestCase {

	protected function tearDown(): void {
		MemberAccessExtension::getInstance()->setSchemaOverride( null );

		parent::tearDown();
	}

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

	/**
	 * A wiki that loaded the extension without running update.php has nothing to manage yet, which
	 * the caller is told rather than left to read out of a database error.
	 */
	public function testCallerIsToldWhenTheWikiHasNoTablesYet(): void {
		MemberAccessExtension::getInstance()->setSchemaOverride( new MissingSchema() );

		$response = $this->runHandler(
			MemberAccessExtension::newListGroupsApi(),
			$this->newRequest( 'GET' )
		);

		$this->assertError( 'schema_missing', 503, $response );
	}

	/**
	 * Who may call comes first: a wiki without its tables answers a caller who may not manage
	 * members exactly as it would with them.
	 */
	public function testCallerWithoutTheRightIsRefusedWhenTheWikiHasNoTablesYet(): void {
		MemberAccessExtension::getInstance()->setSchemaOverride( new MissingSchema() );

		$response = $this->runHandler(
			MemberAccessExtension::newListGroupsApi(),
			$this->newRequest( 'GET' ),
			$this->outsider()
		);

		$this->assertError( 'permission_denied', 403, $response );
	}

	public function testBodyWithoutTheExpectedFieldIsTreatedAsEmpty(): void {
		$response = $this->runHandler(
			MemberAccessExtension::newCreateGroupApi(),
			$this->newRequest( 'POST', [ 'nickname' => 'Acme' ] )
		);

		$this->assertError( 'invalid_group_name', 400, $response );
	}

}
