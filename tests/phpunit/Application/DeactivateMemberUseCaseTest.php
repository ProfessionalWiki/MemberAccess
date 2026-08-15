<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\DeactivateMemberUseCase;
use ProfessionalWiki\MemberAccess\Application\DeactivationResult;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryMemberRepository;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyMemberBlocker;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\DeactivateMemberUseCase
 */
class DeactivateMemberUseCaseTest extends TestCase {

	private const int MEMBER_ID = 7;
	private const int ADMIN_ID = 3;
	private const string EMAIL = 'jane@example.com';

	private InMemoryMemberRepository $members;
	private SpyLogger $logger;

	protected function setUp(): void {
		$this->members = new InMemoryMemberRepository();
		$this->logger = new SpyLogger();

		$email = NormalizedEmail::fromString( self::EMAIL );
		$this->assertNotNull( $email );
		$this->members->recordMember( userId: self::MEMBER_ID, email: $email, groupId: 1 );
	}

	public function testMemberIsDeactivated(): void {
		$result = $this->newUseCase( new SpyMemberBlocker() )->deactivate( self::MEMBER_ID, self::ADMIN_ID );

		$this->assertSame( DeactivationResult::Deactivated, $result );
		$this->assertFalse( $this->members->getMember( self::MEMBER_ID )?->isActive() );
	}

	public function testMemberIsBlockedByTheActingAdmin(): void {
		$blocker = new SpyMemberBlocker();

		$this->newUseCase( $blocker )->deactivate( self::MEMBER_ID, self::ADMIN_ID );

		$this->assertSame( self::ADMIN_ID, $blocker->performerWhoBlocked( self::MEMBER_ID ) );
	}

	public function testAccountThatIsNoMemberIsRefused(): void {
		$result = $this->newUseCase( new SpyMemberBlocker() )->deactivate( 8, self::ADMIN_ID );

		$this->assertSame( DeactivationResult::NotAMember, $result );
	}

	public function testAccountThatIsNoMemberIsNotBlocked(): void {
		$blocker = new SpyMemberBlocker();

		$this->newUseCase( $blocker )->deactivate( 8, self::ADMIN_ID );

		$this->assertNull( $blocker->performerWhoBlocked( 8 ) );
	}

	public function testMemberStaysActiveWhenTheBlockCannotBePlaced(): void {
		$result = $this->newUseCase( new SpyMemberBlocker( blockSucceeds: false ) )
			->deactivate( self::MEMBER_ID, self::ADMIN_ID );

		$this->assertSame( DeactivationResult::BlockFailed, $result );
		$this->assertTrue( $this->members->getMember( self::MEMBER_ID )?->isActive() );
	}

	public function testDeactivatingAgainIsAccepted(): void {
		$useCase = $this->newUseCase( new SpyMemberBlocker() );
		$useCase->deactivate( self::MEMBER_ID, self::ADMIN_ID );

		$this->assertSame(
			DeactivationResult::Deactivated,
			$useCase->deactivate( self::MEMBER_ID, self::ADMIN_ID )
		);
	}

	public function testDeactivationIsLoggedWithoutTheAddress(): void {
		$this->newUseCase( new SpyMemberBlocker() )->deactivate( self::MEMBER_ID, self::ADMIN_ID );

		$this->assertNotSame( '', $this->logger->getLog() );
		$this->assertStringNotContainsString( self::EMAIL, $this->logger->getLog() );
	}

	private function newUseCase( SpyMemberBlocker $blocker ): DeactivateMemberUseCase {
		return new DeactivateMemberUseCase(
			members: $this->members,
			blocker: $blocker,
			logger: $this->logger
		);
	}

}
