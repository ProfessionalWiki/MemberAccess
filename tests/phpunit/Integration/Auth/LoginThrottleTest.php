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
 * MediaWiki throttles login attempts per client IP and per account, and asks the requests being
 * submitted which account is logging in. These pin that it gets an answer for a code request, so
 * that everyone at one client IP no longer draws on a single shared budget.
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

	public function testOneAddressDoesNotUseUpTheLoginAttemptsOfAnother(): void {
		$this->exhaustTheLoginAttemptsOf( 'jane@example.com' );

		$response = $this->requestCode( 'john@example.com' );

		$this->assertSame( AuthenticationResponse::UI, $response->status );
	}

	public function testRepeatedRequestsForOneAddressRunOutOfLoginAttempts(): void {
		$this->exhaustTheLoginAttemptsOf( 'jane@example.com' );

		$response = $this->requestCode( 'jane@example.com' );

		$this->assertRefusedByTheLoginThrottle( $response );
	}

	/**
	 * A request holding no address names no account to count against, and the code request's own
	 * throttle does not count one either, so the client IP has to keep counting it. A request that
	 * counted against nothing would take the throttle off this flow entirely.
	 */
	public function testRequestsWithoutAnAddressRunOutOfLoginAttempts(): void {
		$this->exhaustTheLoginAttemptsOf( '' );

		$response = $this->requestCode( '' );

		$this->assertRefusedByTheLoginThrottle( $response );
	}

	public function testRequestsWithAMistypedAddressRunOutOfLoginAttempts(): void {
		$this->exhaustTheLoginAttemptsOf( 'jane#example.com' );

		$response = $this->requestCode( 'jane#example.com' );

		$this->assertRefusedByTheLoginThrottle( $response );
	}

	/**
	 * Two requests naming different accounts in one submission is a conflict MediaWiki raises
	 * rather than resolves, and not everything that asks who is logging in catches it. Sharing the
	 * login form's own username field, and passing on what it holds untouched, is what keeps the
	 * two from disagreeing. The address here is submitted padded and oddly capitalised, so that
	 * tidying it up on the way through would show.
	 */
	public function testSubmittingAPasswordAsWellStillNamesOneAccount(): void {
		$requests = AuthenticationRequest::loadRequestsFromSubmission(
			[ new LoginCodeRequest(), $this->passwordRequest() ],
			[
				'username' => ' Jane@Example.com ',
				LoginCodeRequest::BUTTON_NAME => true,
				'password' => 'a password typed into the other box'
			]
		);

		$this->assertCount( 2, $requests );
		$this->assertSame( ' Jane@Example.com ', AuthenticationRequest::getUsernameFromRequests( $requests ) );
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
