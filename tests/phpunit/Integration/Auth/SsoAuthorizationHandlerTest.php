<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
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
	 * The account is created by the identity provider's plugin, under a name of its choosing, which
	 * is rarely the address. Provisioning has to recognise the account by that name.
	 */
	public function testNewIdentityIsMarkedForProvisioningUnderTheNameItsAccountWillHave(): void {
		$this->allow( '@example.com' );

		$this->authorize( $this->newIdentity( 'jane@example.com' ) );

		$this->assertSame( 'SsoNewcomer', $this->pendingProvisioning()?->username );
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
		return $this->newHandlerWith( allowlistApplies: true );
	}

	private function newHandlerWith( bool $allowlistApplies ): SsoAuthorizationHandler {
		$extension = MemberAccessExtension::getInstance();

		return new SsoAuthorizationHandler(
			allowlistApplies: $allowlistApplies,
			matcher: $extension->newAllowlistMatcher(),
			members: $extension->newMemberRepository(),
			authManager: $this->getServiceContainer()->getAuthManager(),
			logger: $this->logger
		);
	}

	private function authorize( User $user ): bool {
		return $this->authorizeThrough( $this->newHandler(), $user );
	}

	private function authorizeWithoutTheAllowlist( User $user ): bool {
		return $this->authorizeThrough( $this->newHandlerWith( allowlistApplies: false ), $user );
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
		$user = $this->getServiceContainer()->getUserFactory()->newFromName( 'SsoNewcomer' );

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

	private function existingMember( string $email ): User {
		$user = $this->existingUser( $email );
		$normalized = NormalizedEmail::fromString( $email );

		$this->assertNotNull( $normalized );

		MemberAccessExtension::getInstance()->newMemberRepository()->recordMember(
			userId: $user->getId(),
			email: $normalized,
			groupId: $this->newGroupId()
		);

		return $user;
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
