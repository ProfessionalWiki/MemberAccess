<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\REST;

use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MainConfigNames;
use MediaWiki\RenameUser\RenameuserSQL;
use MediaWiki\Rest\ResponseInterface;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
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
	 * The roster row gone, nothing of this extension refuses the parked account a password reset
	 * anymore, and the reset finds accounts by their address. The address has to go with the row,
	 * or it would mail the removed member a way back in.
	 */
	public function testRemovingStripsTheAddressOffTheParkedAccount(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );
		$this->confirmAddressOf( $userId, 'jane@example.com' );

		$this->removeThrough( $userId );

		$this->assertSame( '', $this->emailOf( $userId ) );
	}

	/**
	 * The username the removed member held is what every later login with their address arrives
	 * at, so the account has to stop holding it.
	 */
	public function testRemovedMembersAccountIsParkedUnderAReservedName(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );

		$this->removeThrough( $userId );

		$this->assertSame( 'Removed member ' . $userId, $this->nameOf( $userId ) );
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

	/**
	 * Renaming whoever holds the name out of the way is not this endpoint's to do.
	 */
	public function testMemberIsKeptWhenTheReservedNameIsTaken(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );
		$name = $this->nameOf( $userId );
		$this->createAccountNamed( 'Removed member ' . $userId );

		$response = $this->removeThrough( $userId );

		$this->assertError( 'reserved_name_taken', 409, $response );
		$this->assertNotNull( $this->rosterRowOf( $userId ) );
		$this->assertSame( $name, $this->nameOf( $userId ) );
	}

	/**
	 * An identity provider settles on the username of the members it admits, so one of them can
	 * already be sitting on the name their own removal wants. Reading that as taken would leave
	 * them unremovable.
	 */
	public function testMemberAlreadyUnderTheReservedNameIsStillRemoved(): void {
		$userId = $this->newMember( $this->groupId, 'jane@example.com' );
		$this->renameAccount( $userId, 'Removed member ' . $userId );

		$response = $this->removeThrough( $userId );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertNull( $this->rosterRowOf( $userId ) );
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
	 * Two admitted addresses can arrive at one username, since MediaWiki drops the leading
	 * underscore of "_alice@example.com". The first one there takes the account, and the other is
	 * refused at every login after that. Removing the member is what frees the name again.
	 */
	public function testAddressRefusedOverAUsernameClashLogsInOnceTheMemberIsRemoved(): void {
		$this->admitTheDomain();
		$this->logIn( '_alice@example.com' );
		$squatterId = $this->idOfAccountNamed( 'Alice@example.com' );

		$this->assertSame( AuthenticationResponse::FAIL, $this->logIn( 'alice@example.com' )->status );

		$this->removeThrough( $squatterId );

		$this->assertSame( AuthenticationResponse::PASS, $this->logIn( 'alice@example.com' )->status );
		$freshId = $this->idOfAccountNamed( 'Alice@example.com' );
		$this->assertNotSame( $squatterId, $freshId );
		$this->assertSame( 'alice@example.com', $this->rosterRowOf( $freshId )?->email );
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

	private function idOfAccountNamed( string $name ): int {
		$user = $this->getServiceContainer()->getUserIdentityLookup()
			->getUserIdentityByName( $name, IDBAccessObject::READ_LATEST );

		$this->assertNotNull( $user, "there is no account named $name" );

		return $user->getId();
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

	private function renameAccount( int $userId, string $name ): void {
		$renamed = ( new RenameuserSQL(
			(string)$this->nameOf( $userId ),
			$name,
			$userId,
			$this->getTestSysop()->getUser()
		) )->rename();

		$this->assertTrue( $renamed );
	}

	private function createAccountNamed( string $name ): void {
		$user = $this->getServiceContainer()->getUserFactory()->newFromName( $name );

		$this->assertNotNull( $user );
		$user->addToDatabase();
	}

	private function recordAsMember( int $userId, string $email ): void {
		$normalized = NormalizedEmail::fromString( $email );

		$this->assertNotNull( $normalized );

		MemberAccessExtension::getInstance()->newMemberRepository()
			->recordMember( userId: $userId, email: $normalized, groupId: $this->groupId );
	}

	private function admitTheDomain(): void {
		$value = AllowlistValue::fromString( '@example.com' );

		$this->assertNotNull( $value );

		MemberAccessExtension::getInstance()->newAllowlistRepository()
			->addEntry( groupId: $this->groupId, value: $value, actorId: 1 );

		$this->setService( 'Emailer', new SpyEmailer() );
		$this->registerOurAuthenticationProvider();
		$this->overrideConfigValue( MainConfigNames::GroupPermissions, array_replace_recursive(
			$this->getConfVar( MainConfigNames::GroupPermissions ),
			[ '*' => [ 'autocreateaccount' => true ] ]
		) );

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
