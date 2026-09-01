<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\Member;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\MemberAuthenticationProvider;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\PendingProvisioning;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\SsoAuthorizationHandler;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\SsoAuthorizationHandler
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\PendingProvisioning
 */
class SsoAuthorizationHandlerTest extends MediaWikiIntegrationTestCase {

	/**
	 * The name the identity provider's plugin is about to create the account under, which for a
	 * member is the opaque one the extension minted for that login.
	 * {@see \ProfessionalWiki\MemberAccess\EntryPoints\Auth\SsoUsernameProcessor}
	 */
	private const string MINTED_NAME = 'Member AB2345';

	private const string PLUGIN_NAME = 'Jane of Acme';

	private SpyLogger $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = new SpyLogger();
	}

	public function testNewIdentityWithAnAdmittedAddressIsAuthorized(): void {
		$this->allow( '@example.com' );

		$this->assertTrue( $this->authorize( $this->newIdentity( 'jane@example.com' ) ) );
	}

	public function testNewIdentityWithAnAdmittedAddressIsMarkedForProvisioning(): void {
		$groupId = $this->allow( '@example.com' );

		$this->authorize( $this->newIdentity( 'jane@example.com' ) );

		$provisioning = $this->pendingProvisioning();

		$this->assertNotNull( $provisioning );
		$this->assertSame( 'jane@example.com', $provisioning->email->value );
		$this->assertSame( $groupId, $provisioning->groupId );
	}

	/**
	 * The account is created by the identity provider's plugin, under the name settled on before
	 * this runs. Provisioning has to recognise the account by that name.
	 */
	public function testNewIdentityIsMarkedForProvisioningUnderTheNameItsAccountWillHave(): void {
		$this->allow( '@example.com' );

		$this->authorize( $this->newIdentity( 'jane@example.com' ) );

		$this->assertSame( self::MINTED_NAME, $this->pendingProvisioning()?->username );
	}

	/**
	 * OpenIDConnect asks the extension what to name an account it is about to create, and is given
	 * a name that identifies nobody. A plugin that offers no such say would create the account
	 * under a name of its own, which is what every account listing would then show, so the login is
	 * refused rather than admitted and lived with.
	 */
	public function testNewIdentityToBeNamedByItsPluginIsRefused(): void {
		$this->allow( '@example.com' );

		$this->assertFalse( $this->authorize( $this->identityNamed( self::PLUGIN_NAME, 'jane@example.com' ) ) );
	}

	public function testRefusalOfAnIdentifyingNameIsRecordedAsAWarning(): void {
		$this->allow( '@example.com' );

		$this->authorize( $this->identityNamed( self::PLUGIN_NAME, 'jane@example.com' ) );

		$this->assertCount( 1, $this->logger->getEntriesAtLevel( 'warning' ) );
	}

	public function testRefusalOfAnIdentifyingNameIsRecordedWithoutTheAddress(): void {
		$this->allow( '@example.com' );

		$this->authorize( $this->identityNamed( self::PLUGIN_NAME, 'jane@example.com' ) );

		$this->assertStringNotContainsString( 'jane@example.com', $this->logger->getLog() );
	}

	/**
	 * A plugin that never handed the extension the address could not have known the login was a
	 * member's while it settled on the name, which is a different thing to put right from a plugin
	 * that was handed it and named the account anyway.
	 */
	public function testRefusalSaysSoWhenNoAddressReachedTheExtensionWhileTheAccountWasNamed(): void {
		$this->allow( '@example.com' );

		$this->authorizeThrough( $this->newHandler(), $this->identityNamed( self::PLUGIN_NAME, 'jane@example.com' ) );

		$this->assertStringContainsString(
			'the extension was given no address while the account was being named',
			$this->logger->getLog()
		);
	}

	public function testRefusalSaysSoWhenThePluginNamedTheAccountDespiteHavingTheAddress(): void {
		$this->allow( '@example.com' );

		$this->authorizeThrough(
			$this->newHandlerGivenTheAddress( 'jane@example.com' ),
			$this->identityNamed( self::PLUGIN_NAME, 'jane@example.com' )
		);

		$this->assertStringContainsString(
			'a name that identifies its holder',
			$this->logger->getLog()
		);
	}

	public function testIdentityRefusedOverItsNameIsNotMarkedForProvisioning(): void {
		$this->allow( '@example.com' );

		$this->authorize( $this->identityNamed( self::PLUGIN_NAME, 'jane@example.com' ) );

		$this->assertNull( $this->pendingProvisioning() );
	}

	public function testRefusalOverANameStopsTheOtherHandlersOfTheHook(): void {
		$this->allow( '@example.com' );

		$authorized = true;
		$continue = $this->newHandler()->onPluggableAuthUserAuthorization(
			$this->identityNamed( self::PLUGIN_NAME, 'jane@example.com' ),
			$authorized
		);

		$this->assertFalse( $continue );
	}

	/**
	 * Staff are no members, so their account keeps the name their plugin settled on.
	 */
	public function testAccountThatIsNoMemberKeepsAPluginChosenNameAndIsAdmitted(): void {
		$this->allow( '@example.com' );
		$staff = $this->existingUserNamed( self::PLUGIN_NAME, 'staff@other.example' );

		$this->assertTrue( $this->authorize( $staff ) );
		$this->assertSame( self::PLUGIN_NAME, $staff->getName() );
	}

	public function testNewIdentityWithAnAddressThatIsNotAdmittedIsRefused(): void {
		$this->allow( '@example.com' );

		$this->assertFalse( $this->authorize( $this->newIdentity( 'jane@other.example' ) ) );
	}

	public function testRefusedIdentityIsNotMarkedForProvisioning(): void {
		$this->allow( '@example.com' );

		$this->authorize( $this->newIdentity( 'jane@other.example' ) );

		$this->assertNull( $this->pendingProvisioning() );
	}

	public function testIdentityWithoutAnAddressIsRefused(): void {
		$this->allow( '@example.com' );

		$this->assertFalse( $this->authorize( $this->newIdentity( '' ) ) );
	}

	/**
	 * A refusal has to be the last word, or a permissive handler of the same hook could hand the
	 * login back. Returning false stops the hook, so no handler after this one runs.
	 */
	public function testRefusalStopsTheOtherHandlersOfTheHook(): void {
		$this->allow( '@example.com' );

		$authorized = true;
		$continue = $this->newHandler()
			->onPluggableAuthUserAuthorization( $this->newIdentity( 'jane@other.example' ), $authorized );

		$this->assertFalse( $continue );
	}

	public function testAuthorizedLoginLetsTheOtherHandlersOfTheHookRun(): void {
		$this->allow( '@example.com' );

		$authorized = true;
		$continue = $this->newHandler()
			->onPluggableAuthUserAuthorization( $this->newIdentity( 'jane@example.com' ), $authorized );

		$this->assertTrue( $continue );
	}

	public function testExistingAccountThatIsNoMemberIsAuthorizedWithoutBeingAdmitted(): void {
		$this->allow( '@example.com' );

		$this->assertTrue( $this->authorize( $this->existingUser( 'staff@other.example' ) ) );
	}

	public function testAdmittingAnAccountThatIsNoMemberIsRecorded(): void {
		$this->allow( '@example.com' );

		$this->authorize( $this->existingUser( 'staff@other.example' ) );

		$this->assertCount( 1, $this->logger->getEntriesAtLevel( 'info' ) );
	}

	public function testAccountThatIsNoMemberAndIsAdmittedAnywayIsNotRecorded(): void {
		$this->allow( '@example.com' );

		$this->authorize( $this->existingUser( 'staff@example.com' ) );

		$this->assertSame( [], $this->logger->getEntriesAtLevel( 'info' ) );
	}

	/**
	 * An account holding the reader group without a roster row is one the allowlist once created
	 * and has since forgotten: a removed member's closed account, or one left behind by a failed
	 * provisioning. An identity provider that recorded the account can hand its logins back to it
	 * for good, so admitting it as staff would put a forgotten account permanently outside the
	 * allowlist.
	 */
	public function testAccountThatWasProvisionedButLeftTheRosterIsRefused(): void {
		$this->allow( '@example.com' );

		$this->assertFalse( $this->authorize( $this->provisionedUserOffTheRoster( 'jane@example.com' ) ) );
	}

	public function testRefusalOfAProvisionedAccountStopsTheOtherHandlersOfTheHook(): void {
		$this->allow( '@example.com' );

		$authorized = true;
		$continue = $this->newHandler()->onPluggableAuthUserAuthorization(
			$this->provisionedUserOffTheRoster( 'jane@example.com' ),
			$authorized
		);

		$this->assertFalse( $continue );
	}

	/**
	 * The account a removal closes keeps the reader group on purpose: it is what marks the account
	 * as one of ours, and what an identity provider that still points at it is refused by.
	 */
	public function testRemovedMembersClosedAccountIsRefused(): void {
		$this->allow( '@example.com' );
		$member = $this->existingMember( 'jane@example.com' );
		$this->addToReaderGroup( $member );

		MemberAccessExtension::getInstance()->newRemoveMemberUseCase()->remove(
			$member->getId(),
			$this->getTestSysop()->getUser()->getId()
		);

		$closed = $this->getServiceContainer()->getUserFactory()->newFromId( $member->getId() );

		$this->assertFalse( $this->authorize( $closed ) );
	}

	public function testMemberWhoseAddressIsNoLongerAdmittedIsRefused(): void {
		$this->allow( '@example.com' );

		$this->assertFalse( $this->authorize( $this->existingMember( 'former@other.example' ) ) );
	}

	public function testMemberWhoseAddressIsStillAdmittedIsAuthorized(): void {
		$this->allow( '@example.com' );

		$this->assertTrue( $this->authorize( $this->existingMember( 'jane@example.com' ) ) );
	}

	public function testMemberIsNotMarkedForProvisioningAgain(): void {
		$this->allow( '@example.com' );

		$this->authorize( $this->existingMember( 'jane@example.com' ) );

		$this->assertNull( $this->pendingProvisioning() );
	}

	/**
	 * The address the roster recorded is the one the allowlist admitted, so it is what the check
	 * has to be about, whatever address the account or the identity provider carries now.
	 */
	public function testMemberIsHeldToTheAddressTheRosterRecorded(): void {
		$this->allow( '@example.com' );
		$member = $this->existingMember( 'former@other.example' );
		$member->setEmail( 'jane@example.com' );

		$this->assertFalse( $this->authorize( $member ) );
	}

	/**
	 * A member the open code route admitted has no group. Once an entry matches the address the
	 * roster holds for them, this login is where that group is written down.
	 */
	public function testMemberWithoutAGroupIsAttributedToTheOneThatAdmitsThemNow(): void {
		$groupId = $this->allow( '@example.com' );
		$member = $this->existingMemberWithoutAGroup( 'jane@example.com' );

		$this->authorize( $member );

		$this->assertSame( $groupId, $this->rosterRowOf( $member )?->groupId );
	}

	public function testIdentityAnotherHandlerAlreadyRefusedStaysRefused(): void {
		$this->allow( '@example.com' );

		$authorized = false;
		$this->newHandler()->onPluggableAuthUserAuthorization( $this->newIdentity( 'jane@example.com' ), $authorized );

		$this->assertFalse( $authorized );
	}

	public function testIdentityAnotherHandlerRefusedIsNotMarkedForProvisioning(): void {
		$this->allow( '@example.com' );

		$authorized = false;
		$this->newHandler()->onPluggableAuthUserAuthorization( $this->newIdentity( 'jane@example.com' ), $authorized );

		$this->assertNull( $this->pendingProvisioning() );
	}

	public function testHandlerBuiltFromTheManifestFactoryWorks(): void {
		$this->overrideConfigValue( 'MemberAccessApplyAllowlistToSso', true );
		$this->allow( '@example.com' );

		$authorized = true;
		MemberAccessExtension::newSsoAuthorizationHandlerHookHandler()
			->onPluggableAuthUserAuthorization( $this->newIdentity( 'jane@example.com' ), $authorized );

		$this->assertTrue( $authorized );
	}

	/**
	 * Runs the hook itself rather than the handler, so that the registration in extension.json is
	 * covered as well: an unregistered handler would admit every single sign-on login.
	 */
	public function testHookRegistrationHoldsSingleSignOnLoginsToTheAllowlist(): void {
		$this->overrideConfigValue( 'MemberAccessApplyAllowlistToSso', true );
		$this->allow( '@example.com' );

		$authorized = true;
		$this->getServiceContainer()->getHookContainer()->run(
			'PluggableAuthUserAuthorization',
			[ $this->newIdentity( 'jane@other.example' ), &$authorized ]
		);

		$this->assertFalse( $authorized );
	}

	/**
	 * A wiki whose single sign-on is not the extension's business: it holds nobody to the
	 * allowlist, and provisions nobody, while the allowlist itself stays as it is.
	 */
	public function testSingleSignOnIsLeftAloneWhenTheAllowlistDoesNotApplyToIt(): void {
		$this->overrideConfigValue( 'MemberAccessApplyAllowlistToSso', false );
		$this->allow( '@example.com' );

		$authorized = true;
		MemberAccessExtension::newSsoAuthorizationHandlerHookHandler()
			->onPluggableAuthUserAuthorization( $this->newIdentity( 'jane@other.example' ), $authorized );

		$this->assertTrue( $authorized );
	}

	/**
	 * Only an explicit true holds the route, here as in the registration handler, so that a wiki
	 * which set something else has single sign-on left alone rather than half held to the list.
	 */
	public function testSingleSignOnIsLeftAloneWhenTheSettingIsNoExplicitTrue(): void {
		$this->overrideConfigValue( 'MemberAccessApplyAllowlistToSso', 1 );
		$this->allow( '@example.com' );

		$authorized = true;
		MemberAccessExtension::newSsoAuthorizationHandlerHookHandler()
			->onPluggableAuthUserAuthorization( $this->newIdentity( 'jane@other.example' ), $authorized );

		$this->assertTrue( $authorized );
	}

	public function testIdentityTheAllowlistWouldRefuseIsAuthorizedWhenItDoesNotApply(): void {
		$this->allow( '@example.com' );

		$this->assertTrue( $this->authorizeWithoutTheAllowlist( $this->newIdentity( 'jane@other.example' ) ) );
	}

	public function testMemberTheAllowlistNoLongerAdmitsIsAuthorizedWhenItDoesNotApply(): void {
		$this->allow( '@example.com' );

		$this->assertTrue( $this->authorizeWithoutTheAllowlist( $this->existingMember( 'former@other.example' ) ) );
	}

	public function testAdmittedIdentityIsNotMarkedForProvisioningWhenTheAllowlistDoesNotApply(): void {
		$this->allow( '@example.com' );

		$this->authorizeWithoutTheAllowlist( $this->newIdentity( 'jane@example.com' ) );

		$this->assertNull( $this->pendingProvisioning() );
	}

	public function testNothingIsRecordedWhenTheAllowlistDoesNotApply(): void {
		$this->allow( '@example.com' );

		$this->authorizeWithoutTheAllowlist( $this->existingUser( 'staff@other.example' ) );

		$this->assertSame( [], $this->logger->getEntries() );
	}

	private function newHandler(): SsoAuthorizationHandler {
		return $this->newHandlerWith( allowlistApplies: true, resolvedAddress: null );
	}

	/**
	 * As the handler is built for a login whose address reached the extension while the account was
	 * being named, which is what OpenIDConnect hands it.
	 */
	private function newHandlerGivenTheAddress( string $address ): SsoAuthorizationHandler {
		return $this->newHandlerWith( allowlistApplies: true, resolvedAddress: $address );
	}

	private function newHandlerWith( bool $allowlistApplies, ?string $resolvedAddress ): SsoAuthorizationHandler {
		$extension = MemberAccessExtension::getInstance();

		return new SsoAuthorizationHandler(
			allowlistApplies: $allowlistApplies,
			matcher: $extension->newAllowlistMatcher(),
			members: $extension->newMemberRepository(),
			userGroups: $this->getServiceContainer()->getUserGroupManager(),
			authManager: $this->getServiceContainer()->getAuthManager(),
			logger: $this->logger,
			resolvedAddress: $resolvedAddress,
			readerGroup: 'reader'
		);
	}

	private function authorize( User $user ): bool {
		return $this->authorizeThrough( $this->newHandler(), $user );
	}

	private function authorizeWithoutTheAllowlist( User $user ): bool {
		return $this->authorizeThrough(
			$this->newHandlerWith( allowlistApplies: false, resolvedAddress: null ),
			$user
		);
	}

	private function authorizeThrough( SsoAuthorizationHandler $handler, User $user ): bool {
		$authorized = true;

		$handler->onPluggableAuthUserAuthorization( $user, $authorized );

		return $authorized;
	}

	/**
	 * Shaped like the user PluggableAuth hands the hook for a login that has no account yet: named
	 * the way its plugin decided, carrying the address the identity provider vouched for.
	 */
	private function newIdentity( string $email ): User {
		return $this->identityNamed( self::MINTED_NAME, $email );
	}

	private function identityNamed( string $name, string $email ): User {
		$user = $this->getServiceContainer()->getUserFactory()->newFromName( $name );

		$this->assertNotNull( $user );
		$user->setEmail( $email );

		return $user;
	}

	private function existingUser( string $email ): User {
		$user = $this->getMutableTestUser()->getUser();
		$user->setEmail( $email );
		$user->saveSettings();

		return $user;
	}

	private function existingUserNamed( string $name, string $email ): User {
		$user = $this->getServiceContainer()->getUserFactory()->newFromName( $name );

		$this->assertNotNull( $user );
		$user->addToDatabase();
		$user->setEmail( $email );
		$user->saveSettings();

		return $user;
	}

	private function provisionedUserOffTheRoster( string $email ): User {
		$user = $this->existingUser( $email );
		$this->addToReaderGroup( $user );

		return $user;
	}

	private function addToReaderGroup( User $user ): void {
		$this->getServiceContainer()->getUserGroupManager()->addUserToGroup( $user, 'reader' );
	}

	private function existingMember( string $email ): User {
		return $this->recordAsMember( $email, groupId: $this->newGroupId() );
	}

	/**
	 * As the open code login route admits people: on the roster, but attributed to nothing.
	 */
	private function existingMemberWithoutAGroup( string $email ): User {
		return $this->recordAsMember( $email, groupId: null );
	}

	private function recordAsMember( string $email, ?int $groupId ): User {
		$user = $this->existingUser( $email );
		$normalized = NormalizedEmail::fromString( $email );

		$this->assertNotNull( $normalized );

		MemberAccessExtension::getInstance()->newMemberRepository()->recordMember(
			userId: $user->getId(),
			email: $normalized,
			groupId: $groupId
		);

		return $user;
	}

	private function rosterRowOf( User $user ): ?Member {
		return MemberAccessExtension::getInstance()->newMemberRepository()
			->getMember( $user->getId(), ReadConsistency::UpToDate );
	}

	private function pendingProvisioning(): ?PendingProvisioning {
		return PendingProvisioning::fromSessionData(
			$this->getServiceContainer()->getAuthManager()
				->getAuthenticationSessionData( MemberAuthenticationProvider::PROVISIONING_SESSION_KEY )
		);
	}

	private function allow( string $value ): int {
		$extension = MemberAccessExtension::getInstance();
		$allowlistValue = AllowlistValue::fromString( $value );

		$this->assertNotNull( $allowlistValue );

		$groupId = $this->newGroupId();
		$extension->newAllowlistRepository()->addEntry( groupId: $groupId, value: $allowlistValue, actorId: 1 );

		return $groupId;
	}

	private function newGroupId(): int {
		return MemberAccessExtension::getInstance()->newMemberGroupRepository()->createGroup( 'Acme' )->id;
	}

}
