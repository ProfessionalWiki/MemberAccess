<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Unit\Auth\AuthenticationProviderTestTrait;
use MediaWiki\User\User;
use Wikimedia\Rdbms\IDBAccessObject;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\Member;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\OpaqueUsername;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\EnterCodeRequest;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\LoginCodeRequest;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\MemberAuthenticationProvider;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\FixedSecretGenerator;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryMemberRepository;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\RefusingUsernameMinter;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyEmailer;
use RuntimeException;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\MemberAuthenticationProvider
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\LoginCodeRequest
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\EnterCodeRequest
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\MemberProvisioner
 */
class MemberAuthenticationProviderTest extends MediaWikiIntegrationTestCase {

	use AuthenticationProviderRegistration;
	use CodeRequestSubmission;
	use AuthenticationProviderTestTrait;

	private const CODE = '12345678';
	private const GROUPED_CODE = '1234 5678';
	private const RETURN_TO_URL = 'https://wiki.example.com/return';
	/**
	 * The name the identity provider's plugin creates the account under, which for a member is the
	 * opaque one the extension minted for that login.
	 * {@see \ProfessionalWiki\MemberAccess\EntryPoints\Auth\SsoUsernameProcessor}
	 */
	private const SSO_USERNAME = 'Member AB2345';

	private SpyEmailer $emailer;

	private bool $groupAdditionsRefused = false;

	protected function setUp(): void {
		parent::setUp();

		$this->emailer = new SpyEmailer();
		$this->setService( 'Emailer', $this->emailer );
		$this->registerOurAuthenticationProvider();
		$this->overrideConfigValue( 'MemberAccessCodeLogin', 'allowlisted' );
		$this->allowAnonymousAutocreation();

		MemberAccessExtension::getInstance()->setStashOverride( new HashBagOStuff() );
		MemberAccessExtension::getInstance()->setSecretGeneratorOverride( new FixedSecretGenerator( self::CODE ) );
	}

	protected function tearDown(): void {
		MemberAccessExtension::getInstance()->setStashOverride( null );
		MemberAccessExtension::getInstance()->setSecretGeneratorOverride( null );
		MemberAccessExtension::getInstance()->setMemberRepositoryOverride( null );
		MemberAccessExtension::getInstance()->setUsernameMinterOverride( null );

		parent::tearDown();
	}

	public function testAdmittedAddressIsMailedACodeAndTheCodeScreenIsShown(): void {
		$this->allow( 'jane@example.com' );

		$response = $this->requestCode( 'jane@example.com' );

		$this->assertSame( AuthenticationResponse::UI, $response->status );
		$this->assertCount( 1, $this->emailer->getSentMails() );
		$this->assertStringContainsString(
			self::GROUPED_CODE,
			$this->emailer->getSentMails()[0]['bodyText']
		);
	}

	/**
	 * The mail shows the code in groups, so a member copying it brings the spaces along. Refusing
	 * that would be refusing the code exactly as it was given to them.
	 */
	public function testCodeIsAcceptedAsItWasShownInTheMail(): void {
		$this->allow( 'jane@example.com' );
		$this->requestCode( 'jane@example.com' );

		$response = $this->enterCode( self::GROUPED_CODE );

		$this->assertSame( AuthenticationResponse::PASS, $response->status );
	}

	public function testCorrectCodeLogsTheMemberInAndCreatesTheAccount(): void {
		$this->allow( 'jane@example.com' );
		$this->requestCode( 'jane@example.com' );

		$response = $this->enterCode( self::CODE );

		$this->assertSame( AuthenticationResponse::PASS, $response->status );
		$this->assertTrue( OpaqueUsername::isOpaque( (string)$response->username ) );
	}

	public function testTwoMembersAreGivenDifferentNames(): void {
		$this->allow( '@example.com' );

		$this->assertNotSame( $this->logIn( 'jane@example.com' )->username, $this->logIn( 'john@example.com' )->username );
	}

	/**
	 * A name that cannot be minted is no reason to hand the address an account somebody else holds,
	 * so the login is turned away. Letting the failure out instead would answer a proven address
	 * with MediaWiki's error page.
	 */
	public function testLoginIsRefusedWhenNoNameCanBeMintedForTheAccount(): void {
		$this->allow( 'jane@example.com' );
		MemberAccessExtension::getInstance()->setUsernameMinterOverride( new RefusingUsernameMinter() );

		$response = $this->logIn( 'jane@example.com' );

		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
		$this->assertSame( 'memberaccess-auth-failed', $response->message?->getKey() );
	}

	public function testCreatedAccountIsAReaderWithAConfirmedEmail(): void {
		$this->allow( 'jane@example.com' );
		$this->requestCode( 'jane@example.com' );
		$this->enterCode( self::CODE );

		$user = $this->accountOfMemberWithAddress( 'jane@example.com' );

		$this->assertContains( 'reader', $this->getServiceContainer()->getUserGroupManager()->getUserGroups( $user ) );
		$this->assertSame( 'jane@example.com', $user->getEmail() );
		$this->assertTrue( $user->isEmailConfirmed() );
	}

	public function testCreatedAccountIsRecordedInTheGroupThatAdmittedIt(): void {
		$groupId = $this->allow( 'jane@example.com' );
		$this->requestCode( 'jane@example.com' );
		$this->enterCode( self::CODE );

		$member = $this->memberWithAddress( 'jane@example.com' );

		$this->assertNotNull( $member );
		$this->assertSame( $groupId, $member->groupId );
		$this->assertSame( 'jane@example.com', $member->email );
	}

	public function testAddressIsAdmittedByADomainEntry(): void {
		$this->allow( '@example.com' );

		$response = $this->logIn( 'jane@example.com' );

		$this->assertSame( AuthenticationResponse::PASS, $response->status );
	}

	/**
	 * An address is no longer a name, so one that could never have been a username is admitted
	 * like any other.
	 */
	public function testAddressThatCouldNotBecomeAUsernameIsAdmitted(): void {
		$this->allow( '@example.com' );

		$this->requestCode( 'jane#doe@example.com' );
		$response = $this->enterCode( self::CODE );

		$this->assertSame( AuthenticationResponse::PASS, $response->status );
		$this->assertTrue( OpaqueUsername::isOpaque( (string)$response->username ) );
	}

	public function testOnlyMemberAccountsCanAuthenticateWithACode(): void {
		$this->allow( 'jane@example.com' );
		$name = (string)$this->logIn( 'jane@example.com' )->username;

		$provider = $this->newInitializedProvider();

		$this->assertTrue( $provider->testUserExists( $name ) );
		$this->assertFalse( $provider->testUserExists( $this->getMutableTestUser()->getUser()->getName() ) );
	}

	public function testCaseVariantsOfOneAddressReachTheSameAccount(): void {
		$this->allow( '@example.com' );
		$first = $this->logIn( 'jane@example.com' );

		$response = $this->logIn( 'JANE@Example.COM' );

		$this->assertSame( $first->username, $response->username );
	}

	public function testWrongCodeShowsTheCodeScreenAgain(): void {
		$this->allow( 'jane@example.com' );
		$this->requestCode( 'jane@example.com' );

		$response = $this->enterCode( '00000000' );

		$this->assertSame( AuthenticationResponse::UI, $response->status );
	}

	public function testCodeStillWorksAfterAWrongAttempt(): void {
		$this->allow( 'jane@example.com' );
		$this->requestCode( 'jane@example.com' );
		$this->enterCode( '00000000' );

		$response = $this->enterCode( self::CODE );

		$this->assertSame( AuthenticationResponse::PASS, $response->status );
	}

	public function testCodeIsBurnedAfterTheAttemptLimitIsReached(): void {
		$this->allow( 'jane@example.com' );
		$this->requestCode( 'jane@example.com' );

		$response = null;
		for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
			$response = $this->enterCode( '00000000' );
		}

		$this->assertSame( AuthenticationResponse::FAIL, $response?->status );
	}

	public function testAddressThatIsNotAdmittedGetsNoMailButTheSameScreen(): void {
		$response = $this->requestCode( 'stranger@example.com' );

		$this->assertSame( AuthenticationResponse::UI, $response->status );
		$this->assertSame( [], $this->emailer->getSentMails() );
	}

	public function testAddressThatIsNotAdmittedCannotLogInWithItsDecoyCode(): void {
		$this->requestCode( 'stranger@example.com' );

		$response = $this->enterCode( self::CODE );

		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
		$this->assertNull( $this->memberWithAddress( 'stranger@example.com' ) );
	}

	public function testMemberRemovedFromTheAllowlistIsRefusedAtTheNextLogin(): void {
		$this->allow( 'jane@example.com' );
		$this->logIn( 'jane@example.com' );
		$this->removeAllAllowlistEntries();

		$this->requestCode( 'jane@example.com' );
		$response = $this->enterCode( self::CODE );

		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
	}

	public function testEmptyAddressIsRefusedWithAnExplanation(): void {
		$response = $this->requestCode( '' );

		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
		$this->assertSame( 'memberaccess-auth-email-missing', $response->message?->getKey() );
	}

	public function testAddressOfNothingButSpacesIsRefusedAsAMissingOne(): void {
		$response = $this->requestCode( '   ' );

		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
		$this->assertSame( 'memberaccess-auth-email-missing', $response->message?->getKey() );
	}

	public function testMalformedAddressIsNamedAsSuch(): void {
		$response = $this->requestCode( 'not-an-address' );

		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
		$this->assertSame( 'memberaccess-auth-email-invalid', $response->message?->getKey() );
	}

	public function testThrottledRequestSaysNothingAboutTheAllowlist(): void {
		$this->allow( 'jane@example.com' );
		$this->overrideConfigValue( 'MemberAccessEmailBurstLimit', 1 );
		$this->requestCode( 'jane@example.com' );

		$response = $this->requestCode( 'jane@example.com' );

		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
		$this->assertSame( 'memberaccess-auth-throttled', $response->message?->getKey() );
	}

	/**
	 * A code proves a mailbox, never an account that was made some other way. The roster is what
	 * joins the two, so an account carrying the address without a roster row is not the one a code
	 * login arrives at.
	 */
	public function testAccountCarryingTheAddressWithoutARosterRowIsNotOpenedWithACode(): void {
		$this->allow( 'jane@example.com' );
		$outsider = $this->accountWithAddress( 'jane@example.com' );

		$this->requestCode( 'jane@example.com' );
		$response = $this->enterCode( self::CODE );
		$member = $this->memberWithAddress( 'jane@example.com' );

		$this->assertSame( AuthenticationResponse::PASS, $response->status );
		$this->assertNotNull( $member );
		$this->assertNotSame( $outsider->getId(), $member->userId );
	}

	/**
	 * A roster row is what points an address at an account, so one pointing at an account that is
	 * no longer there points nowhere. Minting a fresh account for the address would leave two
	 * roster rows holding it.
	 */
	public function testRosterRowWhoseAccountIsGoneRefusesTheLogin(): void {
		$this->recordMemberWithoutAnAccount( 'jane@example.com' );

		$this->requestCode( 'jane@example.com' );
		$response = $this->enterCode( self::CODE );

		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
		$this->assertSame( 'memberaccess-auth-failed', $response->message?->getKey() );
	}

	private function recordMemberWithoutAnAccount( string $email ): void {
		$normalized = NormalizedEmail::fromString( $email );

		$this->assertNotNull( $normalized );

		MemberAccessExtension::getInstance()->newMemberRepository()->recordMember(
			userId: 654321,
			email: $normalized,
			groupId: $this->allow( $email )
		);
	}

	/**
	 * A member provisioned moments ago has not reached a replica yet, and reading one would answer
	 * that this address is new here, which would give it a second account.
	 */
	public function testMemberTheReplicaHasNotSeenYetIsNotGivenASecondAccount(): void {
		$account = $this->getMutableTestUser()->getUser();
		$this->recordMemberBehindTheReplica( $account->getId(), 'jane@example.com' );

		$response = $this->logIn( 'jane@example.com' );

		$this->assertSame( $account->getName(), $response->username );
	}

	private function recordMemberBehindTheReplica( int $userId, string $email ): void {
		$groupId = $this->allow( $email );
		$normalized = NormalizedEmail::fromString( $email );

		$this->assertNotNull( $normalized );

		$members = new InMemoryMemberRepository();
		$members->recordMemberBehindTheReplica( $userId, $normalized, $groupId );

		MemberAccessExtension::getInstance()->setMemberRepositoryOverride( $members );
	}

	public function testSecondLoginOfAMemberDoesNotProvisionAgain(): void {
		$this->allow( 'jane@example.com' );
		$this->logIn( 'jane@example.com' );
		$member = $this->accountOfMemberWithAddress( 'jane@example.com' );
		$this->getServiceContainer()->getUserGroupManager()->removeUserFromGroup( $member, 'reader' );

		$response = $this->logIn( 'jane@example.com' );

		$this->assertSame( AuthenticationResponse::PASS, $response->status );
		$this->assertCount( 1, MemberAccessExtension::getInstance()->newMemberRepository()->listMembers() );
		$this->assertNotContains(
			'reader',
			$this->getServiceContainer()->getUserGroupManager()->getUserGroups( $member )
		);
	}

	/**
	 * An account created for someone else while a member is waiting to be provisioned, a temporary
	 * user for instance, must not be handed the membership that was meant for the member.
	 */
	public function testAccountOtherThanTheAdmittedOneIsNotMadeAMember(): void {
		$this->markAsWaitingToBeProvisioned( 'jane@example.com', self::SSO_USERNAME );
		$stranger = $this->getMutableTestUser()->getUser();

		$this->newInitializedProvider()->autoCreatedAccount( $stranger, 'SomeOtherProvider' );

		$this->assertNull( MemberAccessExtension::getInstance()->newMemberRepository()
			->getMember( $stranger->getId(), ReadConsistency::UpToDate ) );
	}

	public function testAdmittedAccountIsMadeAMemberWhateverCreatedIt(): void {
		$this->markAsWaitingToBeProvisioned( 'jane@example.com', self::SSO_USERNAME );
		$member = $this->userNamed( self::SSO_USERNAME );
		$member->addToDatabase();

		$this->newInitializedProvider()->autoCreatedAccount( $member, 'SomeOtherProvider' );

		$this->assertNotNull( $this->memberWithAddress( 'jane@example.com' ) );
	}

	/**
	 * Not every identity provider normalises the name it creates an account under, so the name
	 * waiting to be provisioned is put through the same rules the account itself went through.
	 */
	public function testAccountWhoseNameTheProviderLeftUnnormalisedIsStillMadeAMember(): void {
		$this->markAsWaitingToBeProvisioned( 'jane@example.com', 'member AB2345' );
		$member = $this->userNamed( self::SSO_USERNAME );
		$member->addToDatabase();

		$this->newInitializedProvider()->autoCreatedAccount( $member, 'SomeOtherProvider' );

		$this->assertNotNull( $this->memberWithAddress( 'jane@example.com' ) );
	}

	/**
	 * Provisioning that failed has to be able to run again. Forgetting the login the moment it is
	 * attempted would leave the account that was created behind as no member, with nothing left to
	 * make it one.
	 */
	public function testAccountThatCouldNotBeProvisionedIsStillWaitingToBeProvisioned(): void {
		$this->markAsWaitingToBeProvisioned( 'jane@example.com', self::SSO_USERNAME );
		$member = $this->userNamed( self::SSO_USERNAME );
		$member->addToDatabase();
		$this->refuseGroupAdditions();

		$this->provisionExpectingItToFail( $member );

		$this->assertNotNull( $this->getServiceContainer()->getAuthManager()
			->getAuthenticationSessionData( MemberAuthenticationProvider::PROVISIONING_SESSION_KEY ) );
	}

	private function provisionExpectingItToFail( User $account ): void {
		try {
			$this->newInitializedProvider()->autoCreatedAccount( $account, 'SomeOtherProvider' );
		} catch ( RuntimeException ) {
		}
	}

	public function testSingleSignOnAccountNamedByItsProviderIsRecordedInTheGroupThatAdmittedIt(): void {
		$groupId = $this->allow( 'jane@example.com' );

		$this->logInThroughSingleSignOn( 'jane@example.com' );

		$member = $this->memberWithAddress( 'jane@example.com' );
		$this->assertSame( $groupId, $member?->groupId );
		$this->assertSame( 'jane@example.com', $member?->email );
	}

	public function testSingleSignOnAccountNamedByItsProviderIsMadeAReader(): void {
		$this->allow( 'jane@example.com' );

		$account = $this->logInThroughSingleSignOn( 'jane@example.com' );

		$this->assertContains( 'reader', $this->getServiceContainer()->getUserGroupManager()->getUserGroups( $account ) );
	}

	public function testSingleSignOnAccountNamedByItsProviderHasItsAddressConfirmed(): void {
		$this->allow( 'jane@example.com' );

		$this->logInThroughSingleSignOn( 'jane@example.com' );

		$account = $this->userNamed( self::SSO_USERNAME );
		$this->assertSame( 'jane@example.com', $account->getEmail() );
		$this->assertTrue( $account->isEmailConfirmed() );
	}

	/**
	 * A first single sign-on login on a wiki that holds that route to the allowlist: the gate admits
	 * the address and marks it for provisioning, and the identity provider's plugin then creates the
	 * account under a name of its own choosing.
	 */
	private function logInThroughSingleSignOn( string $email ): User {
		$this->overrideConfigValue( 'MemberAccessApplyAllowlistToSso', true );

		$identity = $this->userNamed( self::SSO_USERNAME );
		$identity->setEmail( $email );

		$authorized = true;
		MemberAccessExtension::newSsoAuthorizationHandlerHookHandler()
			->onPluggableAuthUserAuthorization( $identity, $authorized );

		$account = $this->userNamed( self::SSO_USERNAME );
		$account->addToDatabase();

		$this->newInitializedProvider()->autoCreatedAccount( $account, 'PluggableAuth' );

		return $account;
	}

	public function testNobodyIsLeftWaitingToBeProvisionedAfterALogin(): void {
		$this->allow( 'jane@example.com' );

		$this->logIn( 'jane@example.com' );

		$this->assertNull( $this->getServiceContainer()->getAuthManager()
			->getAuthenticationSessionData( MemberAuthenticationProvider::PROVISIONING_SESSION_KEY ) );
	}

	/**
	 * Members have one way in. Offering to link the account to another login would take core to
	 * this provider's linking methods, which it does not have.
	 */
	public function testMemberAccountsCannotBeLinkedToAnotherLogin(): void {
		$this->overrideConfigValue( MainConfigNames::AuthManagerConfig, [
			'preauth' => [],
			'primaryauth' => $this->ourAuthenticationProviderConfig(),
			'secondaryauth' => []
		] );

		$this->assertFalse( $this->getServiceContainer()->getAuthManager()->canLinkAccounts() );
	}

	/**
	 * Core reads this to keep the code out of logs and out of a URL.
	 */
	public function testEnteredCodeIsMarkedSensitive(): void {
		$fields = ( new EnterCodeRequest() )->getFieldInfo();

		$this->assertTrue( $fields[EnterCodeRequest::CODE_FIELD]['sensitive'] ?? false );
	}

	/**
	 * What makes the provider abstain: a submission that pressed some other button carries no code
	 * request, however filled in the username box it shares is.
	 */
	public function testAnotherProvidersButtonSubmitsNoCodeRequest(): void {
		$requests = AuthenticationRequest::loadRequestsFromSubmission(
			[ new LoginCodeRequest() ],
			[ 'username' => 'jane@example.com' ]
		);

		$this->assertSame( [], $requests );
	}

	public function testProviderAbstainsWhenItsButtonWasNotPressed(): void {
		$response = $this->newInitializedProvider()
			->beginPrimaryAuthentication( [ new PasswordAuthenticationRequest() ] );

		$this->assertSame( AuthenticationResponse::ABSTAIN, $response->status );
	}

	public function testPasswordLoginStillWorksAlongsideTheCodeButton(): void {
		$testUser = $this->getMutableTestUser();
		$request = new PasswordAuthenticationRequest();
		$request->username = $testUser->getUser()->getName();
		$request->password = $testUser->getPassword();

		$response = $this->getServiceContainer()->getAuthManager()
			->beginAuthentication( [ $request ], self::RETURN_TO_URL );

		$this->assertSame( AuthenticationResponse::PASS, $response->status );
	}

	public function testMemberLoginIsRememberedSoItOutlivesTheBrowserSession(): void {
		$this->allow( 'jane@example.com' );

		$this->logIn( 'jane@example.com' );

		$this->assertTrue( RequestContext::getMain()->getRequest()->getSession()->shouldRememberUser() );
	}

	public function testLoginFailsWhenTheNewAccountCannotBeMadeAReader(): void {
		$this->allow( 'jane@example.com' );
		$this->refuseGroupAdditions();
		$this->requestCode( 'jane@example.com' );

		$this->expectException( RuntimeException::class );
		$this->enterCode( self::CODE );
	}

	public function testVisitorIsNotSignedInWhenTheNewAccountCannotBeMadeAReader(): void {
		$this->allow( 'jane@example.com' );
		$this->refuseGroupAdditions();
		$this->requestCode( 'jane@example.com' );

		$this->enterCodeExpectingProvisioningToFail();

		$this->assertFalse( RequestContext::getMain()->getRequest()->getSession()->getUser()->isRegistered() );
	}

	/**
	 * The account a failed provisioning leaves behind is on no roster, so nothing points the
	 * address at it: the next login mints a name of its own and the member ends up on the account
	 * that was provisioned.
	 */
	public function testAccountLeftBehindByAFailedProvisioningIsNotWhereALaterLoginArrives(): void {
		$this->allow( 'jane@example.com' );
		$this->refuseGroupAdditions();
		$this->requestCode( 'jane@example.com' );
		$this->enterCodeExpectingProvisioningToFail();
		$this->allowGroupAdditionsAgain();

		$this->requestCode( 'jane@example.com' );
		$response = $this->enterCode( self::CODE );

		$this->assertSame( AuthenticationResponse::PASS, $response->status );
		$this->assertSame(
			$this->memberWithAddress( 'jane@example.com' )?->userId,
			$this->userNamed( (string)$response->username )->getId()
		);
	}

	/**
	 * A group addition is refused by handlers of core's UserAddGroup hook, which other extensions
	 * use to hold group membership to their own rules.
	 */
	private function refuseGroupAdditions(): void {
		$this->groupAdditionsRefused = true;

		$this->setTemporaryHook(
			'UserAddGroup',
			fn ( User $user, string &$group, ?string &$expiry ): bool => !$this->groupAdditionsRefused
		);
	}

	private function allowGroupAdditionsAgain(): void {
		$this->groupAdditionsRefused = false;
	}

	private function markAsWaitingToBeProvisioned( string $email, string $username ): void {
		$this->getServiceContainer()->getAuthManager()->setAuthenticationSessionData(
			MemberAuthenticationProvider::PROVISIONING_SESSION_KEY,
			[ 'username' => $username, 'email' => $email, 'groupId' => $this->allow( $email ) ]
		);
	}

	private function enterCodeExpectingProvisioningToFail(): void {
		try {
			$this->enterCode( self::CODE );
		} catch ( RuntimeException ) {
		}
	}

	private function allowAnonymousAutocreation(): void {
		$this->overrideConfigValue( MainConfigNames::GroupPermissions, array_replace_recursive(
			$this->getConfVar( MainConfigNames::GroupPermissions ),
			[ '*' => [ 'autocreateaccount' => true ] ]
		) );
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

	private function allow( string $value ): int {
		$extension = MemberAccessExtension::getInstance();
		$allowlistValue = AllowlistValue::fromString( $value );

		$this->assertNotNull( $allowlistValue );

		$groupId = $extension->newMemberGroupRepository()->createGroup( 'Acme' )->id;
		$extension->newAllowlistRepository()->addEntry( groupId: $groupId, value: $allowlistValue, actorId: 1 );

		return $groupId;
	}

	private function removeAllAllowlistEntries(): void {
		$extension = MemberAccessExtension::getInstance();

		foreach ( $extension->newMemberGroupRepository()->listGroups() as $group ) {
			foreach ( $extension->newAllowlistRepository()->listEntries( $group->id ) as $entry ) {
				$extension->newAllowlistRepository()->removeEntry( $entry->id );
			}
		}
	}

	private function requestCode( string $email ): AuthenticationResponse {
		$response = $this->getServiceContainer()->getAuthManager()
			->beginAuthentication( $this->submittedCodeRequest( $email ), self::RETURN_TO_URL );

		$this->runDeferredUpdates();

		return $response;
	}

	private function enterCode( string $code ): AuthenticationResponse {
		$request = new EnterCodeRequest();
		$request->memberaccessCode = $code;

		return $this->getServiceContainer()->getAuthManager()->continueAuthentication( [ $request ] );
	}

	private function logIn( string $email ): AuthenticationResponse {
		$this->requestCode( $email );

		return $this->enterCode( self::CODE );
	}

	private function userNamed( string $name ): User {
		$user = $this->getServiceContainer()->getUserFactory()->newFromName( $name );

		$this->assertNotNull( $user );
		$user->load( IDBAccessObject::READ_LATEST );

		return $user;
	}

	private function memberWithAddress( string $email ): ?Member {
		$normalized = NormalizedEmail::fromString( $email );

		$this->assertNotNull( $normalized );

		return MemberAccessExtension::getInstance()->newMemberRepository()
			->findMemberByEmail( $normalized, ReadConsistency::UpToDate );
	}

	private function accountOfMemberWithAddress( string $email ): User {
		$member = $this->memberWithAddress( $email );

		$this->assertNotNull( $member );

		$user = $this->getServiceContainer()->getUserFactory()->newFromId( $member->userId );
		$user->load( IDBAccessObject::READ_LATEST );

		return $user;
	}

	/**
	 * An account the extension had no hand in, carrying an address of its own.
	 */
	private function accountWithAddress( string $email ): User {
		$user = $this->getMutableTestUser()->getUser();
		$user->setEmail( $email );
		$user->saveSettings();

		return $user;
	}

	private function runDeferredUpdates(): void {
		DeferredUpdates::doUpdates();
	}

}
