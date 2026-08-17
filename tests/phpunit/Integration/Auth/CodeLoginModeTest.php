<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Unit\Auth\AuthenticationProviderTestTrait;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\DeactivationResult;
use ProfessionalWiki\MemberAccess\Application\Member;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\EnterCodeRequest;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\LoginCodeRequest;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\MemberAuthenticationProvider;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\FixedSecretGenerator;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyEmailer;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * What each setting of the one-time code route lets in, and what it leaves alone.
 *
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\Application\CodeLoginMode
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\MemberAuthenticationProvider
 * @covers \ProfessionalWiki\MemberAccess\MemberAccessExtension
 */
class CodeLoginModeTest extends MediaWikiIntegrationTestCase {

	use AuthenticationProviderRegistration;
	use CodeRequestSubmission;
	use AuthenticationProviderTestTrait;

	private const CODE = '12345678';
	private const RETURN_TO_URL = 'https://wiki.example.com/return';
	private const ADMITTED_ADDRESS = 'jane@example.com';
	private const UNLISTED_ADDRESS = 'stranger@other.example';
	private const UNKNOWN_SETTING = 'sometimes';

	private SpyEmailer $emailer;

	protected function setUp(): void {
		parent::setUp();

		$this->emailer = new SpyEmailer();
		$this->setService( 'Emailer', $this->emailer );
		$this->registerOurAuthenticationProvider();
		$this->allowAnonymousAutocreation();

		MemberAccessExtension::getInstance()->setStashOverride( new HashBagOStuff() );
		MemberAccessExtension::getInstance()->setSecretGeneratorOverride( new FixedSecretGenerator( self::CODE ) );
	}

	protected function tearDown(): void {
		MemberAccessExtension::getInstance()->setStashOverride( null );
		MemberAccessExtension::getInstance()->setSecretGeneratorOverride( null );
		MemberAccessExtension::getInstance()->setLoggerOverride( null );
		// The extension outlives the test, and with it the setting it has read. Left standing, a
		// test could be handed a reading, and a warning already said, that another test caused.
		MemberAccessExtension::getInstance()->forgetCodeLoginMode();

		parent::tearDown();
	}

	public function testLoginFormOffersTheCodeButtonByDefault(): void {
		$this->assertTrue( $this->loginFormOffersTheCodeButton() );
	}

	public function testCodeLoginThatIsOffTakesTheButtonOffTheLoginForm(): void {
		$this->setCodeLogin( 'off' );

		$this->assertFalse( $this->loginFormOffersTheCodeButton() );
	}

	public function testCodeLoginThatIsOffAbstainsFromACodeRequestSubmittedAnyway(): void {
		$this->setCodeLogin( 'off' );
		$this->allow( self::ADMITTED_ADDRESS );

		$response = $this->newInitializedProvider()
			->beginPrimaryAuthentication( $this->submittedCodeRequest( self::ADMITTED_ADDRESS ) );

		$this->assertSame( AuthenticationResponse::ABSTAIN, $response->status );
	}

	public function testCodeLoginThatIsOffMailsNoCode(): void {
		$this->setCodeLogin( 'off' );
		$this->allow( self::ADMITTED_ADDRESS );

		$this->newInitializedProvider()
			->beginPrimaryAuthentication( $this->submittedCodeRequest( self::ADMITTED_ADDRESS ) );
		DeferredUpdates::doUpdates();

		$this->assertSame( [], $this->emailer->getSentMails() );
	}

	/**
	 * A code asked for while the route was still there, entered once it is gone. The route being
	 * gone is the refusal, reached before the address is considered at all.
	 */
	public function testCodeIsRefusedOnceTheRouteIsTakenAway(): void {
		$this->allow( self::ADMITTED_ADDRESS );
		$this->requestCode( self::ADMITTED_ADDRESS );
		$this->setCodeLogin( 'off' );

		$response = $this->enterCode( self::CODE );

		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
		$this->assertSame( 'memberaccess-auth-failed', $response->message?->getKey() );
	}

	public function testCodeLoginThatIsOffLeavesPasswordLoginAlone(): void {
		$this->setCodeLogin( 'off' );
		$testUser = $this->getMutableTestUser();
		$request = new PasswordAuthenticationRequest();
		$request->username = $testUser->getUser()->getName();
		$request->password = $testUser->getPassword();

		$response = $this->getServiceContainer()->getAuthManager()
			->beginAuthentication( [ $request ], self::RETURN_TO_URL );

		$this->assertSame( AuthenticationResponse::PASS, $response->status );
	}

	public function testCodeLoginThatIsOffLeavesSingleSignOnAlone(): void {
		$this->setCodeLogin( 'off' );
		$this->allow( self::ADMITTED_ADDRESS );

		$this->assertTrue( $this->authorizeThroughSingleSignOn( self::ADMITTED_ADDRESS ) );
	}

	public function testOpenCodeLoginAdmitsAnAddressTheAllowlistDoesNotHave(): void {
		$this->setCodeLogin( 'open' );

		$response = $this->logIn( self::UNLISTED_ADDRESS );

		$this->assertSame( AuthenticationResponse::PASS, $response->status );
		$this->assertSame( 'Stranger@other.example', $response->username );
	}

	public function testOpenCodeLoginMailsTheCodeToAnAddressTheAllowlistDoesNotHave(): void {
		$this->setCodeLogin( 'open' );

		$this->requestCode( self::UNLISTED_ADDRESS );

		$this->assertCount( 1, $this->emailer->getSentMails() );
	}

	public function testOpenCodeLoginRecordsTheNewMemberWithoutAGroup(): void {
		$this->setCodeLogin( 'open' );

		$this->logIn( self::UNLISTED_ADDRESS );

		$member = $this->memberNamed( 'Stranger@other.example' );

		$this->assertNotNull( $member, 'the login has to leave a member behind' );
		$this->assertNull( $member->groupId );
	}

	public function testOpenCodeLoginStillAttributesTheGroupThatAdmitsTheAddress(): void {
		$this->setCodeLogin( 'open' );
		$groupId = $this->allow( self::ADMITTED_ADDRESS );

		$this->logIn( self::ADMITTED_ADDRESS );

		$this->assertSame( $groupId, $this->memberNamed( 'Jane@example.com' )?->groupId );
	}

	/**
	 * A member the open route admitted has no group until an entry matches them. Their next login
	 * is where that group is written down, so the roster stops calling them ungrouped.
	 */
	public function testMemberAdmittedWithoutAGroupIsAttributedOnceAnEntryMatchesThem(): void {
		$this->setCodeLogin( 'open' );
		$this->logIn( self::UNLISTED_ADDRESS );
		$groupId = $this->allow( self::UNLISTED_ADDRESS );

		$this->logIn( self::UNLISTED_ADDRESS );

		$this->assertSame( $groupId, $this->memberNamed( 'Stranger@other.example' )?->groupId );
	}

	/**
	 * An open route lets anyone start a login, which is what makes this the defence that matters:
	 * a proven mailbox may only open the account the roster ties to it.
	 */
	public function testOpenCodeLoginStillRefusesAnAccountTheRosterDoesNotTieToTheAddress(): void {
		$this->setCodeLogin( 'open' );
		$this->userNamed( 'Stranger@other.example' )->addToDatabase();

		$this->requestCode( self::UNLISTED_ADDRESS );
		$response = $this->enterCode( self::CODE );

		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
		$this->assertSame( 'memberaccess-auth-failed', $response->message?->getKey() );
	}

	public function testOpenCodeLoginStillRefusesAnAddressThatCannotBecomeAUsername(): void {
		$this->setCodeLogin( 'open' );

		$this->requestCode( 'jane#doe@other.example' );
		$response = $this->enterCode( self::CODE );

		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
	}

	/**
	 * The allowlist is consulted again at every login, so applying it to a route that was open ends
	 * the access of everyone it does not admit, exactly as removing an entry does.
	 */
	public function testMemberAdmittedWhileOpenIsRefusedOnceTheAllowlistApplies(): void {
		$this->setCodeLogin( 'open' );
		$this->logIn( self::UNLISTED_ADDRESS );
		$this->setCodeLogin( 'allowlisted' );

		$response = $this->logIn( self::UNLISTED_ADDRESS );

		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
	}

	public function testMemberTheAllowlistAdmitsKeepsLoggingInOnceItApplies(): void {
		$this->setCodeLogin( 'open' );
		$this->allow( self::ADMITTED_ADDRESS );
		$this->logIn( self::ADMITTED_ADDRESS );
		$this->setCodeLogin( 'allowlisted' );

		$response = $this->logIn( self::ADMITTED_ADDRESS );

		$this->assertSame( AuthenticationResponse::PASS, $response->status );
	}

	/**
	 * What keeps a deactivated member out is the block their deactivation places, which only the
	 * authentication chain core assembles for itself acts on.
	 */
	public function testDeactivatedMemberCannotLogInWithACodeWhileTheRouteIsOpen(): void {
		$this->useTheAuthenticationChainCoreConfigures();
		$this->setCodeLogin( 'open' );
		$this->logIn( self::UNLISTED_ADDRESS );
		$this->deactivate( 'Stranger@other.example' );

		$response = $this->logIn( self::UNLISTED_ADDRESS );

		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
	}

	public function testDeactivatedMemberIsMailedNoFurtherCode(): void {
		$this->setCodeLogin( 'open' );
		$this->logIn( self::UNLISTED_ADDRESS );
		$this->deactivate( 'Stranger@other.example' );

		$this->requestCode( self::UNLISTED_ADDRESS );

		$this->assertCount(
			1,
			$this->emailer->getSentMails(),
			'nothing beyond the code that admitted them may be mailed'
		);
	}

	/**
	 * A wiki can drop the password providers, which the "local login" switch of PluggableAuth does,
	 * and the code route has to stand on its own then.
	 */
	public function testCodeLoginIsOfferedWithoutThePasswordProviders(): void {
		$this->removeThePasswordProviders();

		$this->assertTrue( $this->loginFormOffersTheCodeButton() );
	}

	public function testCodeLoginWorksWithoutThePasswordProviders(): void {
		$this->removeThePasswordProviders();
		$this->allow( self::ADMITTED_ADDRESS );

		$this->assertSame( AuthenticationResponse::PASS, $this->logIn( self::ADMITTED_ADDRESS )->status );
	}

	public function testUnknownSettingHoldsTheRouteToTheAllowlist(): void {
		$this->setCodeLogin( self::UNKNOWN_SETTING );

		$response = $this->logIn( self::UNLISTED_ADDRESS );

		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
	}

	public function testUnknownSettingLeavesTheRouteWorking(): void {
		$this->setCodeLogin( self::UNKNOWN_SETTING );
		$this->allow( self::ADMITTED_ADDRESS );

		$this->assertSame( AuthenticationResponse::PASS, $this->logIn( self::ADMITTED_ADDRESS )->status );
	}

	/**
	 * Said once, however often the setting is read, or every login page view would repeat it.
	 */
	public function testUnknownSettingIsWarnedAboutOnce(): void {
		$logger = new SpyLogger();
		MemberAccessExtension::getInstance()->setLoggerOverride( $logger );
		$this->setCodeLogin( self::UNKNOWN_SETTING );

		MemberAccessExtension::getInstance()->newAuthenticationProvider();
		MemberAccessExtension::getInstance()->newAuthenticationProvider();

		$this->assertCount( 1, $logger->getEntriesAtLevel( 'warning' ) );
	}

	private function setCodeLogin( string $mode ): void {
		$this->overrideConfigValue( 'MemberAccessCodeLogin', $mode );
	}

	private function loginFormOffersTheCodeButton(): bool {
		$requests = $this->getServiceContainer()->getAuthManager()
			->getAuthenticationRequests( AuthManager::ACTION_LOGIN );

		return AuthenticationRequest::getRequestByClass( $requests, LoginCodeRequest::class ) !== null;
	}

	/**
	 * The wiki this runs on may configure an authentication chain of its own, which is why the one
	 * core assembles for itself is put back where a test needs what that chain does.
	 */
	private function useTheAuthenticationChainCoreConfigures(): void {
		$config = $this->getConfVar( MainConfigNames::AuthManagerAutoConfig );
		$config['primaryauth'] += $this->ourAuthenticationProviderConfig();

		$this->overrideConfigValue( MainConfigNames::AuthManagerConfig, $config );
	}

	/**
	 * Leaves the wiki with the one primary provider that asks for no password, which is what
	 * PluggableAuth's local login switch amounts to.
	 */
	private function removeThePasswordProviders(): void {
		$config = $this->getConfVar( MainConfigNames::AuthManagerConfig );
		$config['primaryauth'] = $this->ourAuthenticationProviderConfig();

		$this->overrideConfigValue( MainConfigNames::AuthManagerConfig, $config );
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

	private function deactivate( string $username ): void {
		$result = MemberAccessExtension::getInstance()->newDeactivateMemberUseCase()->deactivate(
			userId: $this->userNamed( $username )->getId(),
			performerId: $this->getTestSysop()->getUser()->getId()
		);

		$this->assertSame( DeactivationResult::Deactivated, $result );
	}

	private function allow( string $value ): int {
		$extension = MemberAccessExtension::getInstance();
		$allowlistValue = AllowlistValue::fromString( $value );

		$this->assertNotNull( $allowlistValue );

		$groupId = $extension->newMemberGroupRepository()->createGroup( 'Acme' )->id;
		$extension->newAllowlistRepository()->addEntry( groupId: $groupId, value: $allowlistValue, actorId: 1 );

		return $groupId;
	}

	private function logIn( string $email ): AuthenticationResponse {
		$this->requestCode( $email );

		return $this->enterCode( self::CODE );
	}

	private function requestCode( string $email ): AuthenticationResponse {
		$response = $this->getServiceContainer()->getAuthManager()
			->beginAuthentication( $this->submittedCodeRequest( $email ), self::RETURN_TO_URL );

		DeferredUpdates::doUpdates();

		return $response;
	}

	private function enterCode( string $code ): AuthenticationResponse {
		$request = new EnterCodeRequest();
		$request->memberaccessCode = $code;

		return $this->getServiceContainer()->getAuthManager()->continueAuthentication( [ $request ] );
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

	private function memberNamed( string $name ): ?Member {
		return MemberAccessExtension::getInstance()->newMemberRepository()
			->getMember( $this->userNamed( $name )->getId(), ReadConsistency::UpToDate );
	}

	private function userNamed( string $name ): User {
		$user = $this->getServiceContainer()->getUserFactory()->newFromName( $name );

		$this->assertNotNull( $user );
		$user->load( IDBAccessObject::READ_LATEST );

		return $user;
	}

	private function allowAnonymousAutocreation(): void {
		$this->overrideConfigValue( MainConfigNames::GroupPermissions, array_replace_recursive(
			$this->getConfVar( MainConfigNames::GroupPermissions ),
			[ '*' => [ 'autocreateaccount' => true ] ]
		) );
	}

}
