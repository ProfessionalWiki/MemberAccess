<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\MainConfigNames;
use MediaWiki\RenameUser\RenameuserSQL;
use MediaWiki\User\User;
use MediaWiki\User\UserRigorOptions;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\OpaqueUsername;
use ProfessionalWiki\MemberAccess\EntryPoints\SchemaChangesHandler;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;
use RuntimeException;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Members were named by whoever admitted them until this update, which is a name update.php has to
 * take away from them: their name is what every listing of accounts on the wiki shows.
 *
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\Persistence\OpaqueNameUpdate
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\SchemaChangesHandler
 */
class OpaqueNameUpdateTest extends MediaWikiIntegrationTestCase {

	private const string ADDRESS_NAME = 'jane@example.com';
	private const string OPAQUE_NAME = 'Member AB2345';

	private SpyLogger $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = new SpyLogger();
		MemberAccessExtension::getInstance()->setLoggerOverride( $this->logger );

		// The accounts this update is about were created while the extension allowed "@" in a
		// username, which it no longer does.
		$this->overrideConfigValue( MainConfigNames::InvalidUsernameCharacters, ':' );
	}

	protected function tearDown(): void {
		MemberAccessExtension::getInstance()->setLoggerOverride( null );

		parent::tearDown();
	}

	public function testMemberNamedAfterTheirAddressIsRenamed(): void {
		$member = $this->newMemberNamed( self::ADDRESS_NAME );

		$this->runTheUpdate();

		$this->assertTrue( OpaqueUsername::isOpaque( $this->nameOf( $member->getId() ) ) );
	}

	/**
	 * A member admitted through single sign-on carries whatever the identity provider called them,
	 * which is a real name, a login handle, or an address without the domain on it. None of those
	 * hold an address, and every one of them names the member.
	 */
	public function testMemberNamedByTheirIdentityProviderIsRenamed(): void {
		$member = $this->newMemberNamed( 'SsoNewcomer' );

		$this->runTheUpdate();

		$this->assertTrue( OpaqueUsername::isOpaque( $this->nameOf( $member->getId() ) ) );
	}

	public function testRenamedMemberIsStillTheSameAccount(): void {
		$member = $this->newMemberNamed( self::ADDRESS_NAME );
		$member->setEmail( self::ADDRESS_NAME );
		$member->saveSettings();

		$this->runTheUpdate();

		$this->assertTrue( OpaqueUsername::isOpaque( $this->nameOf( $member->getId() ) ) );
		$this->assertSame( self::ADDRESS_NAME, $this->accountOf( $member->getId() )->getEmail() );
		$this->assertContains( 'reader', $this->groupsOf( $member ) );
	}

	/**
	 * A wiki that renamed the reader group after admitting members leaves them carrying the old
	 * one, so the roster rather than the group is what says who is a member.
	 */
	public function testMemberOutsideTheReaderGroupIsRenamed(): void {
		$member = $this->newAccountNamed( self::ADDRESS_NAME );
		$this->recordAsMember( $member->getId() );

		$this->runTheUpdate();

		$this->assertTrue( OpaqueUsername::isOpaque( $this->nameOf( $member->getId() ) ) );
	}

	/**
	 * The account a removal leaves behind is off the roster and keeps the reader group, and its
	 * name is what the member was called.
	 */
	public function testAccountTheRosterForgotIsRenamed(): void {
		$forgotten = $this->newForgottenAccountNamed( self::ADDRESS_NAME );

		$this->runTheUpdate();

		$this->assertTrue( OpaqueUsername::isOpaque( $this->nameOf( $forgotten->getId() ) ) );
	}

	/**
	 * An account off the roster is only recognised by the address in its name, since a wiki may
	 * point the reader group at one it already had, holding staff this update has no business
	 * renaming.
	 */
	public function testAccountTheRosterNeverKnewIsLeftAloneWhateverGroupItCarries(): void {
		$staff = $this->newForgottenAccountNamed( 'Alice' );

		$this->runTheUpdate();

		$this->assertSame( 'Alice', $this->nameOf( $staff->getId() ) );
	}

	public function testAccountThatIsNoMemberIsLeftAlone(): void {
		$outsider = $this->newAccountNamed( self::ADDRESS_NAME );

		$this->runTheUpdate();

		$this->assertSame( 'Jane@example.com', $this->nameOf( $outsider->getId() ) );
	}

	public function testMemberThatAlreadyHasAnOpaqueNameIsLeftAlone(): void {
		$member = $this->newMemberNamed( self::OPAQUE_NAME );

		$this->runTheUpdate();

		$this->assertSame( self::OPAQUE_NAME, $this->nameOf( $member->getId() ) );
	}

	public function testRunningTheUpdateAgainChangesNothing(): void {
		$member = $this->newMemberNamed( self::ADDRESS_NAME );
		$this->runTheUpdate();
		$name = $this->nameOf( $member->getId() );

		$this->runTheUpdate();

		$this->assertTrue( OpaqueUsername::isOpaque( $name ) );
		$this->assertSame( $name, $this->nameOf( $member->getId() ) );
	}

	public function testTwoMembersAreGivenDifferentOpaqueNames(): void {
		$first = $this->newMemberNamed( 'jane@example.com' );
		$second = $this->newMemberNamed( 'john@example.com' );

		$this->runTheUpdate();

		$this->assertTrue( OpaqueUsername::isOpaque( $this->nameOf( $first->getId() ) ) );
		$this->assertTrue( OpaqueUsername::isOpaque( $this->nameOf( $second->getId() ) ) );
		$this->assertNotSame( $this->nameOf( $first->getId() ), $this->nameOf( $second->getId() ) );
	}

	public function testRenameIsRecordedByUserIdAlone(): void {
		$member = $this->newMemberNamed( self::ADDRESS_NAME );

		$this->runTheUpdate();

		$this->assertStringContainsString( '"user":' . $member->getId(), $this->logger->getLog() );
		$this->assertStringNotContainsString( 'jane', $this->logger->getLog() );
	}

	/**
	 * A rename that fails fails inside MediaWiki, whose failures name the account they were about:
	 * a database error carries the statement, and the statement carries the name being renamed.
	 * That name is the one thing this update exists to take out of view.
	 */
	public function testFailedRenameIsRecordedWithoutTheNameItWasAbout(): void {
		$this->newMemberNamed( self::ADDRESS_NAME );
		$this->failEveryRenameNamingTheAccount();

		$this->runTheUpdate();

		$this->assertStringNotContainsString( '@', $this->logger->getLog() );
	}

	public function testFailedRenameLeavesTheMemberNamedAsTheyWere(): void {
		$member = $this->newMemberNamed( self::ADDRESS_NAME );
		$this->failEveryRenameNamingTheAccount();

		$this->runTheUpdate();

		$this->assertSame( 'Jane@example.com', $this->nameOf( $member->getId() ) );
	}

	private function failEveryRenameNamingTheAccount(): void {
		$this->setTemporaryHook(
			'RenameUserSQL',
			static function ( RenameuserSQL $rename ): void {
				throw new RuntimeException( 'Renaming ' . $rename->old . ' is not possible here' );
			}
		);
	}

	/**
	 * The rename is an act of the extension's own, not of whoever happens to be running update.php.
	 */
	public function testRenameIsRecordedAsPerformedByTheExtension(): void {
		$this->newMemberNamed( self::ADDRESS_NAME );

		$this->runTheUpdate();

		$this->assertSame( [ 'MemberAccess' ], $this->renameLogPerformers() );
	}

	/**
	 * @return string[]
	 */
	private function renameLogPerformers(): array {
		$rows = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'actor_name' ] )
			->from( 'logging' )
			->join( 'actor', null, 'log_actor = actor_id' )
			->where( [ 'log_type' => 'renameuser' ] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		return array_map( 'strval', $rows );
	}

	/**
	 * The rename log is where the names members were known by survive this update, which is why the
	 * extension keeps that log restricted.
	 */
	public function testRenameIsRecordedInTheRenameLogWithItsReason(): void {
		$this->newMemberNamed( self::ADDRESS_NAME );

		$this->runTheUpdate();

		$this->assertSame( [ 'Email addresses are no longer used as usernames' ], $this->renameLogReasons() );
	}

	/**
	 * @return string[]
	 */
	private function renameLogReasons(): array {
		$rows = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'comment_text' ] )
			->from( 'logging' )
			->join( 'comment', null, 'log_comment_id = comment_id' )
			->where( [ 'log_type' => 'renameuser' ] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		return array_map( 'strval', $rows );
	}

	public function testNothingIsRecordedOnAWikiWithNothingToRename(): void {
		$this->newMemberNamed( self::OPAQUE_NAME );

		$this->runTheUpdate();

		$this->assertSame( [], $this->logger->getEntries() );
	}

	/**
	 * Runs the updates the extension registers with update.php, so that an update this one is not
	 * registered as fails here rather than on the wikis that need it.
	 */
	private function runTheUpdate(): void {
		$updates = $this->registeredUpdates();

		$this->assertCount( 1, $updates, 'update.php has to be given exactly this update to run' );

		$updates[0][0]( $this->newUpdater() );

		DeferredUpdates::doUpdates();
	}

	/**
	 * @return array<int, array<int, callable>>
	 */
	private function registeredUpdates(): array {
		$updates = [];
		$updater = $this->newUpdater();
		$updater->method( 'addExtensionUpdate' )->willReturnCallback(
			static function ( array $update ) use ( &$updates ): void {
				$updates[] = $update;
			}
		);

		( new SchemaChangesHandler() )->onLoadExtensionSchemaUpdates( $updater );

		return $updates;
	}

	private function newUpdater(): DatabaseUpdater {
		$updater = $this->createMock( DatabaseUpdater::class );
		$updater->method( 'getDB' )->willReturn( $this->getDb() );

		return $updater;
	}

	/**
	 * A member as the extension leaves one behind: an account, the reader group, and the roster row
	 * that says whose account it is.
	 */
	private function newMemberNamed( string $name ): User {
		$member = $this->newForgottenAccountNamed( $name );
		$this->recordAsMember( $member->getId() );

		return $member;
	}

	/**
	 * The account a removal closes, or one a failed provisioning left behind: it carries the group
	 * the allowlist gave it, and nothing on the roster names it anymore.
	 */
	private function newForgottenAccountNamed( string $name ): User {
		$account = $this->newAccountNamed( $name );
		$this->getServiceContainer()->getUserGroupManager()->addUserToGroup( $account, 'reader' );

		return $account;
	}

	private function recordAsMember( int $userId ): void {
		$email = NormalizedEmail::fromString( 'member' . $userId . '@example.com' );

		$this->assertNotNull( $email );

		MemberAccessExtension::getInstance()->newMemberRepository()
			->recordMember( userId: $userId, email: $email, groupId: null );
	}

	private function newAccountNamed( string $name ): User {
		$account = $this->getServiceContainer()->getUserFactory()
			->newFromName( $name, UserRigorOptions::RIGOR_CREATABLE );

		$this->assertNotNull( $account, "$name cannot be a username here" );
		$account->addToDatabase();

		return $account;
	}

	/**
	 * @return string[]
	 */
	private function groupsOf( User $member ): array {
		return $this->getServiceContainer()->getUserGroupManager()->getUserGroups( $member );
	}

	private function nameOf( int $userId ): string {
		return $this->accountOf( $userId )->getName();
	}

	private function accountOf( int $userId ): User {
		$account = $this->getServiceContainer()->getUserFactory()->newFromId( $userId );
		$account->load( IDBAccessObject::READ_LATEST );

		return $account;
	}

}
