<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\REST;

use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Rest\ResponseInterface;
use ProfessionalWiki\MemberAccess\Application\Member;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\EnterCodeRequest;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Tests\Integration\Auth\AuthenticationProviderRegistration;
use ProfessionalWiki\MemberAccess\Tests\Integration\Auth\CodeRequestSubmission;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\FixedSecretGenerator;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyEmailer;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\RemoveMemberApi
 * @covers \ProfessionalWiki\MemberAccess\Application\RemoveMemberUseCase
 * @covers \ProfessionalWiki\MemberAccess\Persistence\MediaWikiMemberRemover
 */
class RemoveMemberApiTest extends RestApiTestCase {

	use AuthenticationProviderRegistration;
	use CodeRequestSubmission;

	private const string CODE = '12345678';
	private const string RETURN_TO_URL = 'https://wiki.example.com/return';

	private int $groupId;

	protected function setUp(): void {
		parent::setUp();

		$this->groupId = $this->newGroup( 'Acme' )->id;
	}

	public function testRemovedMemberIsGoneFromTheRoster(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );

		$response = $this->removeThrough( $userId );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertNull( $this->rosterRowOf( $userId ) );
	}

	public function testRemovalAnswersWithWhatWasRemoved(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );

		$response = $this->removeThrough( $userId );

		$this->assertSame( [ 'userId' => $userId, 'removed' => true ], $this->bodyOf( $response ) );
	}

	/**
	 * The roster row gone, nothing of this extension refuses the account a password reset anymore,
	 * and the reset finds accounts by their address. The address has to go with the row, or it
	 * would mail the removed member a way back in.
	 */
	public function testRemovingStripsTheAddressOffTheAccount(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );
		$this->confirmAddressOf( $userId, 'jane@example.com' );

		$this->removeThrough( $userId );

		$this->assertSame( '', $this->emailOf( $userId ) );
	}

	/**
	 * The account is left where it is: its name says nothing about the member who held it, and the
	 * address is what a later login arrives by, which the roster row alone decides.
	 */
	public function testRemovedMembersAccountKeepsTheNameItHad(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );
		$name = $this->nameOf( $userId );

		$this->removeThrough( $userId );

		$this->assertSame( $name, $this->nameOf( $userId ) );
	}

	/**
	 * A remembered login lasts about a month, which a removed member must not keep reading for.
	 */
	public function testRemovingEndsTheAccountsSessions(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );
		$token = $this->sessionTokenOf( $userId );

		$this->removeThrough( $userId );

		$this->assertNotSame( $token, $this->sessionTokenOf( $userId ) );
	}

	public function testDeactivatedMemberCanBeRemoved(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );
		MemberAccessExtension::getInstance()->newMemberRepository()->deactivateMember( $userId );

		$response = $this->removeThrough( $userId );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertNull( $this->rosterRowOf( $userId ) );
	}

	/**
	 * An account nobody was admitted through the allowlist as is not this endpoint's to rename,
	 * whatever else it may be.
	 */
	public function testAccountThatIsNoMemberIsLeftAlone(): void {
		$outsider = $this->getMutableTestUser()->getUser();

		$response = $this->removeThrough( $outsider->getId() );

		$this->assertError( 'not_a_member', 404, $response );
		$this->assertSame( $outsider->getName(), $this->nameOf( $outsider->getId() ) );
	}

	public function testRemovingYourOwnAccountIsRefused(): void {
		$admin = $this->getTestSysop()->getUser();
		$this->recordAsMember( $admin->getId(), 'admin@example.com' );

		$response = $this->removeThrough( $admin->getId() );

		$this->assertError( 'cannot_remove_self', 409, $response );
		$this->assertNotNull( $this->rosterRowOf( $admin->getId() ) );
	}

	public function testRemovingWithoutTheRightToManageMembersIsRefused(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );

		$response = $this->runHandler(
			MemberAccessExtension::newRemoveMemberApi(),
			$this->newRequest( 'DELETE', [], [ 'userId' => (string)$userId ] ),
			$this->outsider()
		);

		$this->assertError( 'permission_denied', 403, $response );
		$this->assertNotNull( $this->rosterRowOf( $userId ) );
	}

	public function testRemovingWithoutACsrfTokenIsRefused(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );

		$response = $this->runHandler(
			MemberAccessExtension::newRemoveMemberApi(),
			$this->newRequest( 'DELETE', [], [ 'userId' => (string)$userId ] ),
			null,
			$this->getSession( false )
		);

		$this->assertError( 'invalid_csrf_token', 403, $response );
		$this->assertNotNull( $this->rosterRowOf( $userId ) );
	}

	private function normalizedEmail( string $email ): NormalizedEmail {
		$normalized = NormalizedEmail::fromString( $email );

		$this->assertNotNull( $normalized );

		return $normalized;
	}

	/**
	 * A roster row naming an account that is not there is stuck in the same way, and has no
	 * username left to free.
	 */
	public function testRosterRowWithoutAnAccountIsForgotten(): void {
		$userId = 654321;
		$this->recordAsMember( $userId, 'ghost@example.com' );

		$response = $this->removeThrough( $userId );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertNull( $this->rosterRowOf( $userId ) );
	}

	/**
	 * What a removal is for: the address is free again, and the login that follows arrives at an
	 * account of its own rather than at the one the removed member held.
	 */
	public function testRemovedMembersAddressReachesANewAccountAtTheNextLogin(): void {
		$this->admitTheDomain();
		$this->logIn( 'alice@example.com' );
		$removedId = $this->idOfTheOnlyMember();

		$this->removeThrough( $removedId );

		$this->assertSame( AuthenticationResponse::PASS, $this->logIn( 'alice@example.com' )->status );
		$freshId = $this->idOfTheOnlyMember();
		$this->assertNotSame( $removedId, $freshId );
		$this->assertSame( 'alice@example.com', $this->rosterRowOf( $freshId )?->email );
	}

	private function idOfTheOnlyMember(): int {
		$members = MemberAccessExtension::getInstance()->newMemberRepository()->listMembers();

		$this->assertCount( 1, $members );

		return $members[0]->userId;
	}

	private function removeThrough( int $userId ): ResponseInterface {
		return $this->runHandler(
			MemberAccessExtension::newRemoveMemberApi(),
			$this->newRequest( 'DELETE', [], [ 'userId' => (string)$userId ] )
		);
	}

	private function rosterRowOf( int $userId ): ?Member {
		return MemberAccessExtension::getInstance()->newMemberRepository()
			->getMember( $userId, ReadConsistency::UpToDate );
	}

	private function nameOf( int $userId ): ?string {
		return $this->getServiceContainer()->getUserIdentityLookup()
			->getUserIdentityByUserId( $userId, IDBAccessObject::READ_LATEST )?->getName();
	}

	private function sessionTokenOf( int $userId ): string {
		$user = $this->getServiceContainer()->getUserFactory()->newFromId( $userId );
		$user->load( IDBAccessObject::READ_LATEST );

		return $user->getToken();
	}

	private function emailOf( int $userId ): string {
		$user = $this->getServiceContainer()->getUserFactory()->newFromId( $userId );
		$user->load( IDBAccessObject::READ_LATEST );

		return $user->getEmail();
	}

	/**
	 * The state provisioning leaves a member's account in: the address on record and confirmed.
	 */
	private function confirmAddressOf( int $userId, string $email ): void {
		$user = $this->getServiceContainer()->getUserFactory()->newFromId( $userId );
		$user->setEmail( $email );
		$user->confirmEmail();
		$user->saveSettings();
	}

	private function recordAsMember( int $userId, string $email ): void {
		MemberAccessExtension::getInstance()->newMemberRepository()
			->recordMember( userId: $userId, email: $this->normalizedEmail( $email ), groupId: $this->groupId );
	}

	private function admitTheDomain(): void {
		$this->newEntry( $this->groupId, '@example.com' );

		$this->setService( 'Emailer', new SpyEmailer() );
		$this->registerOurAuthenticationProvider();

		// The colliding members are made by logging in over the code route, which a wiki has to
		// turn on.
		$this->overrideConfigValue( 'MemberAccessCodeLogin', 'allowlisted' );

		$this->setGroupPermissions( '*', 'autocreateaccount', true );

		MemberAccessExtension::getInstance()->setStashOverride( new HashBagOStuff() );
		MemberAccessExtension::getInstance()->setSecretGeneratorOverride( new FixedSecretGenerator( self::CODE ) );
	}

	protected function tearDown(): void {
		MemberAccessExtension::getInstance()->setStashOverride( null );
		MemberAccessExtension::getInstance()->setSecretGeneratorOverride( null );

		parent::tearDown();
	}

	private function logIn( string $email ): AuthenticationResponse {
		$manager = $this->getServiceContainer()->getAuthManager();
		$manager->beginAuthentication( $this->submittedCodeRequest( $email ), self::RETURN_TO_URL );
		DeferredUpdates::doUpdates();

		$codeEntry = new EnterCodeRequest();
		$codeEntry->memberaccessCode = self::CODE;

		$response = $manager->continueAuthentication( [ $codeEntry ] );
		DeferredUpdates::doUpdates();

		return $response;
	}

}
