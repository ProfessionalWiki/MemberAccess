<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Block\Restriction\PageRestriction;
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

	/**
	 * A block that runs out is not the lockout a deactivation stands for, and it is not this
	 * extension's to replace, so the deactivation is refused rather than recorded on a member who
	 * would be back in the day the block expires.
	 */
	public function testDeactivatingAMemberWhoCarriesABlockThatExpiresFails(): void {
		$this->temporaryBlockByHand();

		$this->assertFalse( $this->blocker->blockMember( $this->member->getId(), $this->adminId ) );
	}

	/**
	 * A partial block leaves the account able to log in, since what keeps a member out looks at
	 * sitewide blocks only.
	 */
	public function testDeactivatingAMemberWhoCarriesAPartialBlockFails(): void {
		$this->partialBlockByHand();

		$this->assertFalse( $this->blocker->blockMember( $this->member->getId(), $this->adminId ) );
	}

	public function testBlockPlacedByHandIsNotLiftedByAReactivation(): void {
		$this->blockByHand( 'Spamming' );

		$result = $this->blocker->unblockMember( $this->member->getId(), $this->adminId );

		$this->assertSame( BlockLiftResult::ForeignBlockKept, $result );
		$this->assertSame( 'Spamming', $this->blockOnMember()?->getReasonComment()->text );
	}

	private function blockByHand( string $reason ): void {
		$this->placeBlockByHand( expiry: 'infinity', reason: $reason, options: [], restrictions: [] );
	}

	private function temporaryBlockByHand(): void {
		$this->placeBlockByHand( expiry: '7 days', reason: 'Edit warring', options: [], restrictions: [] );
	}

	/**
	 * Blocked from one page, which leaves the account able to log in and read everything else.
	 */
	private function partialBlockByHand(): void {
		$this->placeBlockByHand(
			expiry: 'infinity',
			reason: 'Edit warring',
			options: [ 'isPartial' => true ],
			restrictions: [ new PageRestriction( 0, $this->getExistingTestPage()->getId() ) ]
		);
	}

	/**
	 * @param array<string, mixed> $options
	 * @param PageRestriction[] $restrictions
	 */
	private function placeBlockByHand( string $expiry, string $reason, array $options, array $restrictions ): void {
		$status = $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$this->member,
			$this->getTestSysop()->getUser(),
			$expiry,
			$reason,
			$options,
			$restrictions
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
