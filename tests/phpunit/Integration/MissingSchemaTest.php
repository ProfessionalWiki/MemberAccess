<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Tests\Unit\Auth\AuthenticationProviderTestTrait;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\Member;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\LoginCodeRequest;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\MemberAuthenticationProvider;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\PendingProvisioning;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Tests\Integration\Auth\AuthenticationProviderRegistration;
use ProfessionalWiki\MemberAccess\Tests\Integration\Auth\CodeRequestSubmission;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\MissingSchema;

/**
 * What a wiki that loaded the extension without running update.php does. Both login routes are
 * turned on here, so what leaves them inert is the missing schema and nothing else.
 *
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\MemberLoginHandler
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\MemberAuthenticationProvider
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\PasswordResetHandler
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\SsoAuthorizationHandler
 * @covers \ProfessionalWiki\MemberAccess\MemberAccessExtension
 */
class MissingSchemaTest extends MediaWikiIntegrationTestCase {

	use AuthenticationProviderRegistration;
	use AuthenticationProviderTestTrait;
	use CodeRequestSubmission;

	private const ADMITTED_ADDRESS = 'jane@example.com';

	protected function setUp(): void {
		parent::setUp();

		$this->registerOurAuthenticationProvider();
		$this->overrideConfigValue( 'MemberAccessCodeLogin', 'allowlisted' );
		$this->overrideConfigValue( 'MemberAccessApplyAllowlistToSso', true );

		MemberAccessExtension::getInstance()->setSchemaOverride( new MissingSchema() );
	}

	protected function tearDown(): void {
		MemberAccessExtension::getInstance()->setSchemaOverride( null );

		parent::tearDown();
	}

	public function testLoginOfAMemberIsNotRecorded(): void {
		$member = $this->newMember();

		$this->getServiceContainer()->getHookContainer()->run( 'UserLoggedIn', [ $member ] );

		$this->assertNull( $this->rosterRowOf( $member )?->lastLoginTimestamp );
	}

	public function testTheCodeButtonIsNotOnTheLoginForm(): void {
		$this->assertFalse( $this->loginFormOffersTheCodeButton() );
	}

	public function testACodeRequestSubmittedAnywayIsAbstainedFrom(): void {
		$this->allow( self::ADMITTED_ADDRESS );

		$response = $this->newInitializedProvider()
			->beginPrimaryAuthentication( $this->submittedCodeRequest( self::ADMITTED_ADDRESS ) );

		$this->assertSame( AuthenticationResponse::ABSTAIN, $response->status );
	}

	public function testNobodyIsKnownAsAMember(): void {
		$member = $this->newMember();

		$this->assertFalse( $this->newInitializedProvider()->testUserExists( $member->getName() ) );
	}

	/**
	 * Refusing a password is what keeps a member to their login route. With no roster to read there
	 * are no members, and the accounts a wiki has are none of the extension's business.
	 */
	public function testAPasswordIsNotRefused(): void {
		$request = new PasswordAuthenticationRequest();
		$request->username = $this->newMember()->getName();

		$allowed = $this->newInitializedProvider()->providerAllowsAuthenticationDataChange( $request );

		$this->assertTrue( $allowed->isGood() );
	}

	public function testSingleSignOnLoginIsNotRefused(): void {
		$this->allow( self::ADMITTED_ADDRESS );

		$this->assertTrue( $this->authorizeThroughSingleSignOn( 'stranger@other.example' ) );
	}

	public function testSingleSignOnLoginIsNotMarkedForProvisioning(): void {
		$this->allow( self::ADMITTED_ADDRESS );

		$this->authorizeThroughSingleSignOn( self::ADMITTED_ADDRESS );

		$this->assertNull( $this->pendingProvisioning() );
	}

	public function testMemberIsLeftInAPasswordReset(): void {
		$member = $this->newMember();
		$users = [ $member ];
		$error = '';

		$this->getServiceContainer()->getHookContainer()->run(
			'SpecialPasswordResetOnSubmit',
			[ &$users, [ 'Username' => null, 'Email' => self::ADMITTED_ADDRESS ], &$error ]
		);

		$this->assertSame( [ $member ], $users );
	}

	private function loginFormOffersTheCodeButton(): bool {
		$requests = $this->getServiceContainer()->getAuthManager()
			->getAuthenticationRequests( AuthManager::ACTION_LOGIN );

		return AuthenticationRequest::getRequestByClass( $requests, LoginCodeRequest::class ) !== null;
	}

	private function newInitializedProvider(): MemberAuthenticationProvider {
		$provider = MemberAccessExtension::getInstance()->newAuthenticationProvider();

		$this->initProvider(
			$provider,
			$this->getServiceContainer()->getMainConfig(),
			null,
			$this->getServiceContainer()->getAuthManager(),
			$this->getServiceContainer()->getHookContainer(),
			$this->getServiceContainer()->getUserNameUtils()
		);

		return $provider;
	}

	private function authorizeThroughSingleSignOn( string $email ): bool {
		$identity = $this->getServiceContainer()->getUserFactory()->newFromName( 'Jane of Acme' );

		$this->assertNotNull( $identity );
		$identity->setEmail( $email );

		$authorized = true;
		MemberAccessExtension::newSsoAuthorizationHandlerHookHandler()
			->onPluggableAuthUserAuthorization( $identity, $authorized );

		return $authorized;
	}

	private function pendingProvisioning(): ?PendingProvisioning {
		return PendingProvisioning::fromSessionData(
			$this->getServiceContainer()->getAuthManager()
				->getAuthenticationSessionData( MemberAuthenticationProvider::PROVISIONING_SESSION_KEY )
		);
	}

	/**
	 * The roster the wiki is about to lose: recorded while the tables are still there, so that what
	 * the entry points do with a member can be seen at all.
	 */
	private function newMember(): User {
		$user = $this->getMutableTestUser()->getUser();
		$email = NormalizedEmail::fromString( 'member' . $user->getId() . '@example.com' );

		$this->assertNotNull( $email );

		MemberAccessExtension::getInstance()->newMemberRepository()
			->recordMember( userId: $user->getId(), email: $email, groupId: 1 );

		return $user;
	}

	private function rosterRowOf( User $user ): ?Member {
		return MemberAccessExtension::getInstance()->newMemberRepository()
			->getMember( $user->getId(), ReadConsistency::UpToDate );
	}

	private function allow( string $value ): void {
		$extension = MemberAccessExtension::getInstance();
		$allowlistValue = AllowlistValue::fromString( $value );

		$this->assertNotNull( $allowlistValue );

		$extension->newAllowlistRepository()->addEntry(
			groupId: $extension->newMemberGroupRepository()->createGroup( 'Acme' )->id,
			value: $allowlistValue,
			actorId: 1
		);
	}

}
