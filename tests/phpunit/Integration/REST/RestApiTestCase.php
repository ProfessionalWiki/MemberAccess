<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\REST;

use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\RequestData;
use MediaWiki\Rest\ResponseInterface;
use MediaWiki\Session\CsrfTokenSet;
use MediaWiki\Session\Session;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;

/**
 * Shared plumbing for the /member-access/v0/ endpoint tests: running a handler, reading its
 * response, and the accounts the endpoints tell apart.
 */
abstract class RestApiTestCase extends MediaWikiIntegrationTestCase {

	use HandlerTestTrait;

	protected function newGroup( string $name ): MemberGroup {
		return MemberAccessExtension::getInstance()->newMemberGroupRepository()->createGroup( $name );
	}

	protected function newEntry( int $groupId, string $value ): int {
		$parsed = AllowlistValue::fromString( $value );

		$this->assertNotNull( $parsed );

		$entry = MemberAccessExtension::getInstance()->newAllowlistRepository()
			->addEntry( groupId: $groupId, value: $parsed, actorId: 1 );

		$this->assertNotNull( $entry );

		return $entry->id;
	}

	protected function newMember( int $groupId, string $email ): int {
		$user = $this->getMutableTestUser()->getUser();
		$normalized = NormalizedEmail::fromString( $email );

		$this->assertNotNull( $normalized );

		MemberAccessExtension::getInstance()->newMemberRepository()
			->recordMember( userId: $user->getId(), email: $normalized, groupId: $groupId );

		return $user->getId();
	}

	protected function manager(): Authority {
		return $this->getTestSysop()->getAuthority();
	}

	protected function outsider(): Authority {
		return $this->getTestUser()->getAuthority();
	}

	protected function anon(): Authority {
		return $this->getServiceContainer()->getUserFactory()->newAnonymous();
	}

	protected function csrfTokens(): CsrfTokenSet {
		return new CsrfTokenSet( $this->getServiceContainer()->getUserFactory()->newAnonymous()->getRequest() );
	}

	/**
	 * @param array<string, mixed> $body
	 * @param array<string, string> $pathParams
	 * @param array<string, string> $headers
	 */
	protected function newRequest(
		string $method,
		array $body = [],
		array $pathParams = [],
		array $headers = []
	): RequestData {
		$data = [ 'method' => $method, 'pathParams' => $pathParams, 'headers' => $headers ];

		if ( $method !== 'GET' ) {
			$data['bodyContents'] = json_encode( $body );
			$data['headers'] += [ 'content-type' => 'application/json' ];
		}

		return new RequestData( $data );
	}

	protected function runHandler(
		Handler $handler,
		RequestData $request,
		?Authority $authority = null,
		?Session $session = null
	): ResponseInterface {
		return $this->executeHandler(
			$handler,
			$request,
			[],
			[],
			[],
			[],
			$authority ?? $this->manager(),
			$session
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function bodyOf( ResponseInterface $response ): array {
		$data = json_decode( (string)$response->getBody(), true );

		$this->assertIsArray( $data, 'the response body must be a JSON object' );

		return $data;
	}

	protected function assertError( string $errorCode, int $status, ResponseInterface $response ): void {
		$this->assertSame( $status, $response->getStatusCode() );
		$this->assertSame( $errorCode, $this->bodyOf( $response )['errorCode'] ?? null );
	}

}
