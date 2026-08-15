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

	private function newHandler(): SsoAuthorizationHandler {
		$extension = MemberAccessExtension::getInstance();

		return new SsoAuthorizationHandler(
			matcher: $extension->newAllowlistMatcher(),
			members: $extension->newMemberRepository(),
			authManager: $this->getServiceContainer()->getAuthManager(),
			logger: $this->logger
		);
	}

	private function authorize( User $user ): bool {
		$authorized = true;

		$this->newHandler()->onPluggableAuthUserAuthorization( $user, $authorized );

		return $authorized;
	}

	/**
	 * Shaped like the user PluggableAuth hands the hook for a login that has no account yet.
	 */
	private function newIdentity( string $email ): User {
		$user = new User();
		$user->loadDefaults( 'SsoNewcomer' );
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
