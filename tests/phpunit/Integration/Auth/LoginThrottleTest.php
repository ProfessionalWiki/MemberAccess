<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Auth\ThrottlePreAuthenticationProvider;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\LoginCodeRequest;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * A code request asks for its address in a box of its own, so it names no account to MediaWiki and
 * is counted against the client IP. Telling one address from another is the extension's own
 * throttle's job, which is tighter than the one here. {@see \ProfessionalWiki\MemberAccess\Application\RequestThrottle}
 *
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\LoginCodeRequest
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\MemberAuthenticationProvider
 */
class LoginThrottleTest extends MediaWikiIntegrationTestCase {

	use AuthenticationProviderRegistration;
	use CodeRequestSubmission;

	private const ATTEMPTS_ALLOWED = 2;
	private const RETURN_TO_URL = 'https://wiki.example.com/return';

	protected function setUp(): void {
		parent::setUp();

		$this->registerOurAuthenticationProvider();
		$this->overrideConfigValue( 'MemberAccessCodeLogin', 'allowlisted' );
		$this->throttleLoginAttemptsAfter( self::ATTEMPTS_ALLOWED );

		MemberAccessExtension::getInstance()->setStashOverride( new HashBagOStuff() );
	}

	protected function tearDown(): void {
		MemberAccessExtension::getInstance()->setStashOverride( null );

		parent::tearDown();
	}

	public function testRepeatedRequestsRunOutOfLoginAttempts(): void {
		$this->exhaustTheLoginAttemptsOf( 'jane@example.com' );

		$response = $this->requestCode( 'jane@example.com' );

		$this->assertRefusedByTheLoginThrottle( $response );
	}

	/**
	 * The counting is per client IP, so the budget one address spends is gone for every other
	 * address reaching the wiki from there. Pinned because it is what asking for the address in a
	 * box of its own gave up, and what the extension's own per-address throttle has to make up for.
	 */
	public function testOneAddressUsesUpTheLoginAttemptsOfAnother(): void {
		$this->exhaustTheLoginAttemptsOf( 'jane@example.com' );

		$response = $this->requestCode( 'john@example.com' );

		$this->assertRefusedByTheLoginThrottle( $response );
	}

	/**
	 * An address MediaWiki could make nothing of still spends an attempt, or the flow could be
	 * driven without a throttle counting it at all.
	 */
	public function testRequestsWithAMistypedAddressRunOutOfLoginAttempts(): void {
		$this->exhaustTheLoginAttemptsOf( 'jane#example.com' );

		$response = $this->requestCode( 'jane#example.com' );

		$this->assertRefusedByTheLoginThrottle( $response );
	}

	public function testRequestsWithoutAnAddressRunOutOfLoginAttempts(): void {
		$this->exhaustTheLoginAttemptsOf( '' );

		$response = $this->requestCode( '' );

		$this->assertRefusedByTheLoginThrottle( $response );
	}

	/**
	 * The login form carries a box for a username and a box for an address, and nothing stops a
	 * visitor filling both before pressing either button. Two requests naming different accounts is
	 * a conflict MediaWiki raises rather than resolves, so a code request has to name no account at
	 * all. The two boxes are filled with different values here, which is what would raise it.
	 */
	public function testFillingInThePasswordBoxAsWellStillNamesOneAccount(): void {
		$requests = AuthenticationRequest::loadRequestsFromSubmission(
			[ new LoginCodeRequest(), $this->passwordRequest() ],
			[
				LoginCodeRequest::EMAIL_FIELD => 'jane@example.com',
				LoginCodeRequest::BUTTON_NAME => true,
				'username' => 'Some other account',
				'password' => 'a password typed into the other box'
			]
		);

		$this->assertCount( 2, $requests );
		$this->assertSame( 'Some other account', AuthenticationRequest::getUsernameFromRequests( $requests ) );
	}

	/**
	 * The address is what the code is sent to, so it has to survive the submission exactly as it
	 * was typed. It is submitted padded and oddly capitalised here, since tidying it up on the way
	 * through would leave the address the code was sent to disagreeing with the one asked for.
	 */
	public function testTheAddressReachesTheRequestAsSubmitted(): void {
		$requests = AuthenticationRequest::loadRequestsFromSubmission(
			[ new LoginCodeRequest() ],
			[
				LoginCodeRequest::EMAIL_FIELD => ' Jane@Example.com ',
				LoginCodeRequest::BUTTON_NAME => true
			]
		);

		$this->assertSame( 'Jane@Example.com', $requests[0]->address() );
		$this->assertNull( AuthenticationRequest::getUsernameFromRequests( $requests ) );
	}

	private function passwordRequest(): PasswordAuthenticationRequest {
		$request = new PasswordAuthenticationRequest();
		$request->action = AuthManager::ACTION_LOGIN;

		return $request;
	}

	private function exhaustTheLoginAttemptsOf( string $email ): void {
		for ( $attempt = 0; $attempt < self::ATTEMPTS_ALLOWED; $attempt++ ) {
			$this->requestCode( $email );
		}
	}

	private function assertRefusedByTheLoginThrottle( AuthenticationResponse $response ): void {
		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
		$this->assertSame( 'login-throttled', $response->message?->getKey() );
	}

	private function requestCode( string $email ): AuthenticationResponse {
		return $this->getServiceContainer()->getAuthManager()
			->beginAuthentication( $this->submittedCodeRequest( $email ), self::RETURN_TO_URL );
	}

	private function throttleLoginAttemptsAfter( int $attempts ): void {
		$authManagerConfig = $this->getConfVar( MainConfigNames::AuthManagerConfig );
		$authManagerConfig['preauth'][ThrottlePreAuthenticationProvider::class] = [
			'class' => ThrottlePreAuthenticationProvider::class,
			'sort' => 0
		];

		$this->overrideConfigValues( [
			// The throttle counts in the main object cache, and does nothing at all without one.
			MainConfigNames::MainCacheType => CACHE_HASH,
			MainConfigNames::PasswordAttemptThrottle => [ [ 'count' => $attempts, 'seconds' => 300 ] ],
			MainConfigNames::AuthManagerConfig => $authManagerConfig
		] );
	}

}
