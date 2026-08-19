<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

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
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyEmailer;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;
use StatusValue;

/**
 * What a wiki that loaded the extension without running update.php does. Both login routes are
 * turned on here, so what leaves them inert is the missing schema and nothing else.
 *
 * Inert everywhere except around the reader group. It is core's own data rather than the
 * extension's, and provisioning puts it on every account the allowlist creates, so on a wiki whose
 * roster cannot be read it is the one thing left that tells a member's account from staff's.
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

	private SpyEmailer $emailer;

	private SpyLogger $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->emailer = new SpyEmailer();
		$this->logger = new SpyLogger();
		$this->setService( 'Emailer', $this->emailer );
		MemberAccessExtension::getInstance()->setLoggerOverride( $this->logger );
		$this->registerOurAuthenticationProvider();
		$this->overrideConfigValue( 'MemberAccessCodeLogin', 'allowlisted' );
		$this->overrideConfigValue( 'MemberAccessApplyAllowlistToSso', true );
		$this->overrideConfigValues( [
			MainConfigNames::EnableEmail => true,
			MainConfigNames::PasswordResetRoutes => [ 'username' => true, 'email' => true ]
		] );

		MemberAccessExtension::getInstance()->setSchemaOverride( new MissingSchema() );
	}

	protected function tearDown(): void {
		MemberAccessExtension::getInstance()->setSchemaOverride( null );
		MemberAccessExtension::getInstance()->setLoggerOverride( null );

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
	 * Refusing a password is what keeps a member to their login route. An account that never
	 * carried the reader group was made some other way, and is none of the extension's business.
	 */
	public function testAPasswordIsNotRefusedToAnAccountWithoutTheReaderGroup(): void {
		$request = new PasswordAuthenticationRequest();
		$request->username = $this->newAccount()->getName();

		$allowed = $this->newInitializedProvider()->providerAllowsAuthenticationDataChange( $request );

		$this->assertTrue( $allowed->isGood() );
	}

	/**
	 * A password would be a way into a member's account that the allowlist does not govern, and one
	 * the roster cannot be read to refuse. The group the account carries is refused on instead.
	 */
	public function testAPasswordIsRefusedToAnAccountCarryingTheReaderGroup(): void {
		$request = new PasswordAuthenticationRequest();
		$request->username = $this->newAccountCarryingTheReaderGroup()->getName();

		$allowed = $this->newInitializedProvider()->providerAllowsAuthenticationDataChange( $request );

		$this->assertFalse( $allowed->isGood() );
		$this->assertTrue( $allowed->hasMessage( 'memberaccess-auth-password-refused' ) );
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

	/**
	 * The identity provider records the account it authenticated, so a removed member's next login
	 * arrives at their parked account, which keeps the reader group. Leaving that login alone would
	 * hand the account back, and the provider would confirm its address again on the way in.
	 */
	public function testSingleSignOnIntoAnAccountCarryingTheReaderGroupIsRefused(): void {
		$this->assertFalse(
			$this->authorizeThroughSingleSignOnAs( $this->newAccountCarryingTheReaderGroup() )
		);
	}

	/**
	 * Staff sign in through the same identity provider, and were never admitted by the allowlist.
	 * Refusing them too would shut a working provider out of a wiki that has yet to be installed.
	 */
	public function testSingleSignOnIntoAnAccountWithoutTheReaderGroupIsNotRefused(): void {
		$this->assertTrue( $this->authorizeThroughSingleSignOnAs( $this->newAccount() ) );
	}

	/**
	 * A login that used to be admitted is being turned away for a reason nothing on the wiki shows,
	 * so the log is where whoever is looking into it finds out why.
	 */
	public function testRefusedSingleSignOnLoginIsRecorded(): void {
		$this->authorizeThroughSingleSignOnAs( $this->newAccountCarryingTheReaderGroup() );

		$this->assertCount( 1, $this->logger->getEntriesAtLevel( 'warning' ) );
	}

	public function testAccountWithoutTheReaderGroupIsLeftInAPasswordReset(): void {
		$account = $this->newAccount();
		$users = [ $account ];

		$this->submitPasswordReset( $users );

		$this->assertSame( [ $account ], $users );
	}

	public function testAccountCarryingTheReaderGroupIsDroppedFromAPasswordReset(): void {
		$users = [ $this->newAccountCarryingTheReaderGroup() ];

		$this->submitPasswordReset( $users );

		$this->assertSame( [], $users );
	}

	/**
	 * Core's reset finds an account by the address it carries and mails it a temporary password,
	 * which is a way in that no member is to have. Dropping the account leaves the request
	 * answering exactly as one naming nobody does, and nothing in the mailbox either way.
	 */
	public function testPasswordResetOfAnAccountCarryingTheReaderGroupAnswersLikeOneThatIsNotThere(): void {
		$account = $this->newAccountCarryingTheReaderGroup();
		$this->giveAConfirmedAddressTo( $account );

		$status = $this->resetPasswordOf( $account->getName() );

		$this->assertEquals( $this->resetPasswordOf( 'Nobody' ), $status );
		$this->assertSame( [], $this->emailer->getSentMails() );
	}

	/**
	 * Special:PasswordReset asks whether resetting is possible at all with a request that names
	 * nobody. Answering that one with a refusal would take the feature away from the whole wiki.
	 */
	public function testResettingPasswordsStaysAvailableOnTheWiki(): void {
		$this->assertTrue( $this->getServiceContainer()->getPasswordReset()->isEnabled()->isGood() );
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

	/**
	 * A login that has no account yet: named the way the identity provider's plugin decided, and
	 * carrying the address it vouched for.
	 */
	private function authorizeThroughSingleSignOn( string $email ): bool {
		$identity = $this->getServiceContainer()->getUserFactory()->newFromName( 'Jane of Acme' );

		$this->assertNotNull( $identity );
		$identity->setEmail( $email );

		return $this->authorizeThroughSingleSignOnAs( $identity );
	}

	private function authorizeThroughSingleSignOnAs( User $user ): bool {
		$authorized = true;
		MemberAccessExtension::newSsoAuthorizationHandlerHookHandler()
			->onPluggableAuthUserAuthorization( $user, $authorized );

		return $authorized;
	}

	/**
	 * @param User[] &$users
	 */
	private function submitPasswordReset( array &$users ): void {
		$error = '';

		$this->getServiceContainer()->getHookContainer()->run(
			'SpecialPasswordResetOnSubmit',
			[ &$users, [ 'Username' => null, 'Email' => self::ADMITTED_ADDRESS ], &$error ]
		);
	}

	private function resetPasswordOf( string $username ): StatusValue {
		$status = $this->getServiceContainer()->getPasswordReset()
			->execute( $this->getTestSysop()->getUser(), $username );

		DeferredUpdates::doUpdates();

		return $status;
	}

	private function pendingProvisioning(): ?PendingProvisioning {
		return PendingProvisioning::fromSessionData(
			$this->getServiceContainer()->getAuthManager()
				->getAuthenticationSessionData( MemberAuthenticationProvider::PROVISIONING_SESSION_KEY )
		);
	}

	private function newAccount(): User {
		return $this->getMutableTestUser()->getUser();
	}

	/**
	 * An account of the extension's own making, as far as a wiki without the tables can tell:
	 * provisioning adds the group before the roster row, and a removal leaves it on the account it
	 * parks.
	 */
	private function newAccountCarryingTheReaderGroup(): User {
		return $this->getMutableTestUser( [ 'reader' ] )->getUser();
	}

	private function giveAConfirmedAddressTo( User $user ): void {
		$user->setEmail( 'parked' . $user->getId() . '@example.com' );
		$user->confirmEmail();
		$user->saveSettings();
	}

	/**
	 * The roster the wiki is about to lose: recorded while the tables are still there, so that what
	 * the entry points do with a member can be seen at all.
	 */
	private function newMember(): User {
		$user = $this->newAccount();
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
