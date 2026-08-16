<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use MediaWiki\Block\BlockUserFactory;
use MediaWiki\Block\DatabaseBlockStore;
use MediaWiki\Block\UnblockUserFactory;
use MediaWiki\User\UserFactory;
use ProfessionalWiki\MemberAccess\Application\BlockLiftResult;
use ProfessionalWiki\MemberAccess\Application\MemberBlocker;
use Psr\Log\LoggerInterface;

/**
 * Blocks through the same services Special:Block uses, so a deactivation is an ordinary block: it
 * appears in the block log, checks the acting admin's rights, and can be undone by hand.
 */
class MediaWikiMemberBlocker implements MemberBlocker {

	private const string INDEFINITE = 'infinity';
	private const string ALREADY_BLOCKED = 'ipb_already_blocked';

	public function __construct(
		private readonly BlockUserFactory $blockUserFactory,
		private readonly UnblockUserFactory $unblockUserFactory,
		private readonly DatabaseBlockStore $blockStore,
		private readonly UserFactory $userFactory,
		private readonly LoggerInterface $logger
	) {
	}

	/**
	 * Autoblocking is deliberately off: members share office and campus addresses, so it would take
	 * reading rights from everyone behind the one the deactivated member last used. It buys nothing
	 * either, since what keeps a member out is $wgBlockDisablesLogin, which looks at the account.
	 */
	public function blockMember( int $userId, int $performerId ): bool {
		$status = $this->blockUserFactory->newBlockUser(
			$this->userFactory->newFromId( $userId ),
			$this->userFactory->newFromId( $performerId ),
			self::INDEFINITE,
			$this->reason( 'memberaccess-block-reason' ),
			[
				'isCreateAccountBlocked' => true,
				'isEmailBlocked' => true,
				'isUserTalkEditBlocked' => true,
				'isAutoblocking' => false
			]
		)->placeBlock( reblock: false );

		// A block already being there is refused rather than replaced, so it has to be one that
		// ends access by itself. Core refuses that way for any unexpired block, including one that
		// runs out and one that is only partial, neither of which keeps a member out.
		if ( $status->isOK() || ( $status->hasMessage( self::ALREADY_BLOCKED ) && $this->isLockedOut( $userId ) ) ) {
			return true;
		}

		$this->logger->error( 'Blocking a member failed', [ 'status' => $status->__toString() ] );

		return false;
	}

	private function isLockedOut( int $userId ): bool {
		$block = $this->blockStore->newFromTarget( $this->userFactory->newFromId( $userId ), null, true );

		return $block !== null && $block->isSitewide() && $block->getExpiry() === self::INDEFINITE;
	}

	public function unblockMember( int $userId, int $performerId ): BlockLiftResult {
		$target = $this->userFactory->newFromId( $userId );
		$block = $this->blockStore->newFromTarget( $target, null, true );

		if ( $block === null ) {
			return BlockLiftResult::Lifted;
		}

		if ( $block->getReasonComment()->text !== $this->reason( 'memberaccess-block-reason' ) ) {
			$this->logger->info( 'A block placed for another reason was left on a reactivated member', [
				'user' => $userId
			] );

			return BlockLiftResult::ForeignBlockKept;
		}

		$status = $this->unblockUserFactory->newUnblockUser(
			$target,
			$this->userFactory->newFromId( $performerId ),
			$this->reason( 'memberaccess-unblock-reason' )
		)->unblock();

		if ( !$status->isOK() ) {
			$this->logger->error( 'Lifting a member block failed', [ 'status' => $status->__toString() ] );

			return BlockLiftResult::Failed;
		}

		return BlockLiftResult::Lifted;
	}

	private function reason( string $message ): string {
		return wfMessage( $message )->inContentLanguage()->text();
	}

}
