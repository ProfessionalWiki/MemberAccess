<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\MissingSchema;

/**
 * Runs the hook itself rather than the handler, so that the registration in extension.json is
 * covered as well: an unregistered handler would leave every login unrecorded.
 *
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\MemberLoginHandler
 */
class MemberLoginHandlerTest extends MediaWikiIntegrationTestCase {

	private MemberRepository $members;

	protected function setUp(): void {
		parent::setUp();

		$this->members = MemberAccessExtension::getInstance()->newMemberRepository();
	}

	protected function tearDown(): void {
		MemberAccessExtension::getInstance()->setSchemaOverride( null );

		parent::tearDown();
	}

	public function testLoginOfAMemberIsRecorded(): void {
		$user = $this->newMember();

		$this->logIn( $user );

		$this->assertNotNull( $this->members->getMember( $user->getId(), ReadConsistency::UpToDate )?->lastLoginTimestamp );
	}

	public function testLoginOfAnAccountThatIsNoMemberRecordsNothing(): void {
		$this->logIn( $this->getTestUser()->getUser() );

		$this->assertSame( [], $this->members->listMembers() );
	}

	public function testLoginOfAMemberLeavesOtherMembersAlone(): void {
		$other = $this->newMember();

		$this->logIn( $this->newMember() );

		$this->assertNull( $this->members->getMember( $other->getId(), ReadConsistency::UpToDate )?->lastLoginTimestamp );
	}

	/**
	 * This is the one thing the extension does on every login the wiki has, so a wiki that loaded it
	 * without running update.php must have its logins go through rather than fail on a roster that
	 * is not there. The member is recorded first, while the test database still stands in for a wiki
	 * that has the tables: a handler reaching for the roster anyway would find that row and write to
	 * it, which on a real wiki is the database error this guards against.
	 */
	public function testLoginOnAWikiWithoutTheTablesRecordsNothing(): void {
		$user = $this->newMember();
		MemberAccessExtension::getInstance()->setSchemaOverride( new MissingSchema() );

		$this->logIn( $user );

		$this->assertNull( $this->members->getMember( $user->getId(), ReadConsistency::UpToDate )?->lastLoginTimestamp );
	}

	private function newMember(): User {
		$user = $this->getMutableTestUser()->getUser();
		$email = NormalizedEmail::fromString( 'member' . $user->getId() . '@example.com' );

		$this->assertNotNull( $email );

		$this->members->recordMember( userId: $user->getId(), email: $email, groupId: 1 );

		return $user;
	}

	private function logIn( User $user ): void {
		$this->getServiceContainer()->getHookContainer()->run( 'UserLoggedIn', [ $user ] );
	}

}
