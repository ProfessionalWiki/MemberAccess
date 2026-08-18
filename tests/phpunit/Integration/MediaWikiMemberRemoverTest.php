<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\User\User;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\Member;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use ProfessionalWiki\MemberAccess\Application\RemovalResult;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Persistence\MediaWikiMemberRemover;
use Psr\Log\NullLogger;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\Persistence\MediaWikiMemberRemover
 */
class MediaWikiMemberRemoverTest extends MediaWikiIntegrationTestCase {

	/**
	 * A removal that cannot finish has to leave nothing of itself behind: forgetting the roster
	 * row and stripping the address happen before the rename, so refusing the rename has to take
	 * them back. A rename refuses when the account is not under the name it was read as, which is
	 * what a lookup answering a name no account carries stands in for here.
	 */
	public function testFailedRenameLeavesTheMemberAsTheyWere(): void {
		$member = $this->newMemberWithAddress( 'jane@example.com' );

		$result = $this->newRemoverReadingTheAccountAs( 'A name no account has' )
			->removeMember( $member->getId(), $this->getTestSysop()->getUser()->getId() );

		$this->assertSame( RemovalResult::RemovalFailed, $result );
		$this->assertNotNull( $this->rosterRowOf( $member->getId() ) );
		$this->assertSame( 'jane@example.com', $this->emailOf( $member->getId() ) );
	}

	private function newMemberWithAddress( string $email ): User {
		$user = $this->getMutableTestUser()->getUser();
		$normalized = NormalizedEmail::fromString( $email );

		$this->assertNotNull( $normalized );

		MemberAccessExtension::getInstance()->newMemberRepository()
			->recordMember( userId: $user->getId(), email: $normalized, groupId: null );

		$user->setEmail( $email );
		$user->confirmEmail();
		$user->saveSettings();

		return $user;
	}

	private function newRemoverReadingTheAccountAs( string $staleName ): MediaWikiMemberRemover {
		$lookup = $this->createMock( UserIdentityLookup::class );
		$lookup->method( 'getUserIdentityByUserId' )->willReturnCallback(
			static fn ( int $userId ) => new UserIdentityValue( $userId, $staleName )
		);
		$lookup->method( 'getUserIdentityByName' )->willReturn( null );

		$services = $this->getServiceContainer();

		return new MediaWikiMemberRemover(
			connectionProvider: $services->getConnectionProvider(),
			members: MemberAccessExtension::getInstance()->newMemberRepository(),
			userFactory: $services->getUserFactory(),
			userLookup: $lookup,
			logger: new NullLogger()
		);
	}

	private function rosterRowOf( int $userId ): ?Member {
		return MemberAccessExtension::getInstance()->newMemberRepository()
			->getMember( $userId, ReadConsistency::UpToDate );
	}

	private function emailOf( int $userId ): string {
		$user = $this->getServiceContainer()->getUserFactory()->newFromId( $userId );
		$user->load( IDBAccessObject::READ_LATEST );

		return $user->getEmail();
	}

}
