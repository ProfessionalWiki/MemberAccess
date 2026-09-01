<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\RemovalResult;
use ProfessionalWiki\MemberAccess\Application\RemoveMemberUseCase;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryMemberRepository;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyMemberRemover;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\RemoveMemberUseCase
 */
class RemoveMemberUseCaseTest extends TestCase {

	private const int MEMBER_ID = 7;
	private const int OUTSIDER_ID = 8;
	private const int ADMIN_ID = 3;
	private const string EMAIL = 'jane@example.com';

	private InMemoryMemberRepository $members;
	private SpyLogger $logger;

	protected function setUp(): void {
		$this->members = new InMemoryMemberRepository();
		$this->logger = new SpyLogger();

		$this->members->recordMember( userId: self::MEMBER_ID, email: $this->normalizedEmail(), groupId: 1 );
	}

	private function normalizedEmail(): NormalizedEmail {
		$email = NormalizedEmail::fromString( self::EMAIL );

		$this->assertNotNull( $email );

		return $email;
	}

	public function testMemberIsRemoved(): void {
		$result = $this->newUseCase( new SpyMemberRemover() )->remove( self::MEMBER_ID, self::ADMIN_ID );

		$this->assertSame( RemovalResult::Removed, $result );
	}

	public function testMembersAccountIsHandedToTheRemover(): void {
		$remover = new SpyMemberRemover();

		$this->newUseCase( $remover )->remove( self::MEMBER_ID, self::ADMIN_ID );

		$this->assertTrue( $remover->hasRemoved( self::MEMBER_ID ) );
	}

	/**
	 * A member admitted moments ago has not reached a replica yet, and reading one would answer
	 * that the account this is about is no member of ours.
	 */
	public function testMemberTheReplicaHasNotSeenYetIsRemoved(): void {
		$this->members->recordMemberBehindTheReplica( 9, $this->normalizedEmail(), 1 );

		$result = $this->newUseCase( new SpyMemberRemover() )->remove( 9, self::ADMIN_ID );

		$this->assertSame( RemovalResult::Removed, $result );
	}

	public function testAccountThatIsNoMemberIsNotLogged(): void {
		$this->newUseCase( new SpyMemberRemover() )->remove( self::OUTSIDER_ID, self::ADMIN_ID );

		$this->assertSame( [], $this->logger->getEntries() );
	}

	public function testAccountThatIsNoMemberIsRefused(): void {
		$result = $this->newUseCase( new SpyMemberRemover() )->remove( self::OUTSIDER_ID, self::ADMIN_ID );

		$this->assertSame( RemovalResult::NotAMember, $result );
	}

	/**
	 * An account the allowlist never admitted is not this endpoint's to close, whatever else that
	 * account may be.
	 */
	public function testAccountThatIsNoMemberIsLeftAlone(): void {
		$remover = new SpyMemberRemover();

		$this->newUseCase( $remover )->remove( self::OUTSIDER_ID, self::ADMIN_ID );

		$this->assertFalse( $remover->hasRemoved( self::OUTSIDER_ID ) );
	}

	public function testRemovalIsLoggedWithoutTheAddress(): void {
		$this->newUseCase( new SpyMemberRemover() )->remove( self::MEMBER_ID, self::ADMIN_ID );

		$this->assertNotSame( '', $this->logger->getLog() );
		$this->assertStringNotContainsString( self::EMAIL, $this->logger->getLog() );
	}

	private function newUseCase( SpyMemberRemover $remover ): RemoveMemberUseCase {
		return new RemoveMemberUseCase(
			members: $this->members,
			remover: $remover,
			logger: $this->logger
		);
	}

}
