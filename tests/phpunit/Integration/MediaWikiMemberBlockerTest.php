<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\BlockLiftResult;
use ProfessionalWiki\MemberAccess\Application\MemberBlocker;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\Persistence\MediaWikiMemberBlocker
 */
class MediaWikiMemberBlockerTest extends MediaWikiIntegrationTestCase {

	private MemberBlocker $blocker;
	private User $member;
	private int $adminId;

	protected function setUp(): void {
		parent::setUp();

		$this->blocker = MemberAccessExtension::getInstance()->newMemberBlocker();
		$this->member = $this->getMutableTestUser()->getUser();
		$this->adminId = $this->getTestSysop()->getUser()->getId();
	}

	public function testBlockedMemberIsBlockedSitewideAndIndefinitely(): void {
		$this->assertTrue( $this->blocker->blockMember( $this->member->getId(), $this->adminId ) );

		$block = $this->blockOnMember();

		$this->assertTrue( $block?->isSitewide() );
		$this->assertSame( 'infinity', $block?->getExpiry() );
	}

	public function testBlockLocksTheAccountDown(): void {
		$this->blocker->blockMember( $this->member->getId(), $this->adminId );

		$block = $this->blockOnMember();

		$this->assertTrue( $block?->isEmailBlocked() );
		$this->assertTrue( $block?->isCreateAccountBlocked() );
		$this->assertFalse( $block?->isUsertalkEditAllowed() );
	}

	/**
	 * Members share office and campus addresses. An autoblock would take reading rights from
	 * everyone behind the same one, and buys nothing: login enforcement looks at the account.
	 */
	public function testBlockDoesNotReachTheAddressesTheMemberSharesWithOthers(): void {
		$this->blocker->blockMember( $this->member->getId(), $this->adminId );

		$this->assertFalse( $this->blockOnMember()?->isAutoblocking() );
	}

	public function testBlockLogReadsWhyTheAccountWasBlocked(): void {
		$this->blocker->blockMember( $this->member->getId(), $this->adminId );

		$this->assertSame( 'Membership ended', $this->blockOnMember()?->getReasonComment()->text );
	}

	public function testBlockingAMemberWhoIsAlreadyBlockedSucceeds(): void {
		$this->blocker->blockMember( $this->member->getId(), $this->adminId );

		$this->assertTrue( $this->blocker->blockMember( $this->member->getId(), $this->adminId ) );
		$this->assertNotNull( $this->blockOnMember() );
	}

	public function testUnblockingLiftsTheBlock(): void {
		$this->blocker->blockMember( $this->member->getId(), $this->adminId );

		$result = $this->blocker->unblockMember( $this->member->getId(), $this->adminId );

		$this->assertSame( BlockLiftResult::Lifted, $result );
		$this->assertNull( $this->blockOnMember() );
	}

	public function testUnblockingAMemberWhoIsNotBlockedSucceeds(): void {
		$this->assertSame(
			BlockLiftResult::Lifted,
			$this->blocker->unblockMember( $this->member->getId(), $this->adminId )
		);
	}

	public function testBlockPlacedByHandIsNotReplacedByADeactivation(): void {
		$this->blockByHand( 'Spamming' );

		$this->assertTrue( $this->blocker->blockMember( $this->member->getId(), $this->adminId ) );
		$this->assertSame( 'Spamming', $this->blockOnMember()?->getReasonComment()->text );
	}

	public function testBlockPlacedByHandIsNotLiftedByAReactivation(): void {
		$this->blockByHand( 'Spamming' );

		$result = $this->blocker->unblockMember( $this->member->getId(), $this->adminId );

		$this->assertSame( BlockLiftResult::ForeignBlockKept, $result );
		$this->assertSame( 'Spamming', $this->blockOnMember()?->getReasonComment()->text );
	}

	private function blockByHand( string $reason ): void {
		$status = $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$this->member,
			$this->getTestSysop()->getUser(),
			'infinity',
			$reason
		)->placeBlock();

		$this->assertTrue( $status->isOK() );
	}

	public function testBlockingByAnAccountThatMayNotBlockIsRefused(): void {
		$performer = $this->getMutableTestUser()->getUser();

		$this->assertFalse( $this->blocker->blockMember( $this->member->getId(), $performer->getId() ) );
		$this->assertNull( $this->blockOnMember() );
	}

	private function blockOnMember(): ?DatabaseBlock {
		return $this->getServiceContainer()->getDatabaseBlockStore()
			->newFromTarget( $this->member, null, true );
	}

}
