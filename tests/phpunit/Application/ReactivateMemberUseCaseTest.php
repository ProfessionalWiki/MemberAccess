<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\BlockLiftResult;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\ReactivateMemberUseCase;
use ProfessionalWiki\MemberAccess\Application\ReactivationResult;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryMemberRepository;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyMemberBlocker;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\ReactivateMemberUseCase
 */
class ReactivateMemberUseCaseTest extends TestCase {

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
		$this->members->deactivateMember( self::MEMBER_ID );
	}

	public function testMemberIsActiveAgain(): void {
		$result = $this->newUseCase( new SpyMemberBlocker() )->reactivate( self::MEMBER_ID, self::ADMIN_ID );

		$this->assertSame( ReactivationResult::Reactivated, $result );
		$this->assertTrue( $this->members->getMember( self::MEMBER_ID )?->isActive() );
	}

	public function testBlockIsLiftedByTheActingAdmin(): void {
		$blocker = new SpyMemberBlocker();

		$this->newUseCase( $blocker )->reactivate( self::MEMBER_ID, self::ADMIN_ID );

		$this->assertSame( self::ADMIN_ID, $blocker->performerWhoUnblocked( self::MEMBER_ID ) );
	}

	public function testAccountThatIsNoMemberIsRefused(): void {
		$result = $this->newUseCase( new SpyMemberBlocker() )->reactivate( 8, self::ADMIN_ID );

		$this->assertSame( ReactivationResult::NotAMember, $result );
	}

	public function testAccountThatIsNoMemberIsNotUnblocked(): void {
		$blocker = new SpyMemberBlocker();

		$this->newUseCase( $blocker )->reactivate( 8, self::ADMIN_ID );

		$this->assertNull( $blocker->performerWhoUnblocked( 8 ) );
	}

	public function testMemberStaysDeactivatedWhenTheBlockCannotBeLifted(): void {
		$result = $this->newUseCase( new SpyMemberBlocker( liftResult: BlockLiftResult::Failed ) )
			->reactivate( self::MEMBER_ID, self::ADMIN_ID );

		$this->assertSame( ReactivationResult::UnblockFailed, $result );
		$this->assertFalse( $this->members->getMember( self::MEMBER_ID )?->isActive() );
	}

	public function testMemberIsActiveAgainEvenWhenAnotherBlockKeepsTheAccountOut(): void {
		$result = $this->newUseCase( new SpyMemberBlocker( liftResult: BlockLiftResult::ForeignBlockKept ) )
			->reactivate( self::MEMBER_ID, self::ADMIN_ID );

		$this->assertSame( ReactivationResult::ReactivatedButStillBlocked, $result );
		$this->assertTrue( $this->members->getMember( self::MEMBER_ID )?->isActive() );
	}

	public function testReactivationKeepsTheGroupThatAdmittedTheMember(): void {
		$this->newUseCase( new SpyMemberBlocker() )->reactivate( self::MEMBER_ID, self::ADMIN_ID );

		$member = $this->members->getMember( self::MEMBER_ID );

		$this->assertSame( 1, $member?->groupId );
		$this->assertSame( '20260101000000', $member?->creationTimestamp );
	}

	public function testReactivationIsLoggedWithoutTheAddress(): void {
		$this->newUseCase( new SpyMemberBlocker() )->reactivate( self::MEMBER_ID, self::ADMIN_ID );

		$this->assertNotSame( '', $this->logger->getLog() );
		$this->assertStringNotContainsString( self::EMAIL, $this->logger->getLog() );
	}

	private function newUseCase( SpyMemberBlocker $blocker ): ReactivateMemberUseCase {
		return new ReactivateMemberUseCase(
			members: $this->members,
			blocker: $blocker,
			logger: $this->logger
		);
	}

}
