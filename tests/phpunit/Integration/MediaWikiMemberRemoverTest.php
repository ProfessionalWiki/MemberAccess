<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\Session\SessionManager;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\Member;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Persistence\MediaWikiMemberRemover;
use RuntimeException;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\Persistence\MediaWikiMemberRemover
 */
class MediaWikiMemberRemoverTest extends MediaWikiIntegrationTestCase {

	/**
	 * A removal that cannot finish has to leave nothing of itself behind: the roster row is
	 * forgotten before the account gives up its address, and a forgotten row whose account kept
	 * the address is a way back into a reader account.
	 */
	public function testFailureToCloseTheAccountLeavesTheMemberAsTheyWere(): void {
		$member = $this->newMemberWithAddress( 'jane@example.com' );

		$this->removeExpectingItToFail( $member->getId() );

		$this->assertNotNull( $this->rosterRowOf( $member->getId() ) );
		$this->assertSame( 'jane@example.com', $this->emailOf( $member->getId() ) );
	}

	public function testFailureToCloseTheAccountIsNotSwallowed(): void {
		$member = $this->newMemberWithAddress( 'jane@example.com' );
		$remover = $this->newRemoverThatCannotDropTheSessions();

		$this->expectException( RuntimeException::class );
		$remover->removeMember( $member->getId() );
	}

	private function removeExpectingItToFail( int $userId ): void {
		try {
			$this->newRemoverThatCannotDropTheSessions()->removeMember( $userId );
		} catch ( RuntimeException ) {
		}
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

	/**
	 * Stands in for whatever can go wrong once the address has been written off the account, which
	 * is the last thing a removal does and the write a cancelled section has to take back.
	 */
	private function newRemoverThatCannotDropTheSessions(): MediaWikiMemberRemover {
		$sessions = $this->createMock( SessionManager::class );
		$sessions->method( 'invalidateSessionsForUser' )
			->willThrowException( new RuntimeException( 'the sessions could not be dropped' ) );

		return new MediaWikiMemberRemover(
			connectionProvider: $this->getServiceContainer()->getConnectionProvider(),
			members: MemberAccessExtension::getInstance()->newMemberRepository(),
			userFactory: $this->getServiceContainer()->getUserFactory(),
			sessions: $sessions
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
