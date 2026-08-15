<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

use Psr\Log\LoggerInterface;

/**
 * Gives a deactivated member their access back: the block is lifted and the roster timestamp
 * cleared.
 *
 * The account is left exactly as it was. Provisioning belongs to account creation and never runs
 * again, so the member keeps the group that admitted them and the day they joined.
 *
 * A block placed for another reason is not the extension's to lift, so the member becomes active on
 * the roster while the account stays locked out, and the caller is told so.
 */
class ReactivateMemberUseCase {

	public function __construct(
		private readonly MemberRepository $members,
		private readonly MemberBlocker $blocker,
		private readonly LoggerInterface $logger
	) {
	}

	public function reactivate( int $userId, int $performerId ): ReactivationResult {
		$member = $this->members->getMember( $userId, ReadConsistency::UpToDate );

		if ( $member === null ) {
			return ReactivationResult::NotAMember;
		}

		$lift = $this->blocker->unblockMember( $userId, $performerId );

		if ( $lift === BlockLiftResult::Failed ) {
			$this->logger->error( 'Member not reactivated: the block could not be lifted', [
				'email' => NormalizedEmail::hashOf( $member->email )
			] );

			return ReactivationResult::UnblockFailed;
		}

		$this->members->reactivateMember( $userId );

		$this->logger->info( 'Member reactivated', [
			'email' => NormalizedEmail::hashOf( $member->email ),
			'performer' => $performerId
		] );

		return $lift === BlockLiftResult::ForeignBlockKept
			? ReactivationResult::ReactivatedButStillBlocked
			: ReactivationResult::Reactivated;
	}

}
