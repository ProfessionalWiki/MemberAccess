<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

use Psr\Log\LoggerInterface;

/**
 * Ends a member's access: a sitewide indefinite block, which is what actually keeps them out, and
 * the roster timestamp that stops further login codes being sent and marks the member inactive.
 *
 * The block goes first, so a member is never marked inactive while still able to get in.
 */
class DeactivateMemberUseCase {

	public function __construct(
		private readonly MemberRepository $members,
		private readonly MemberBlocker $blocker,
		private readonly LoggerInterface $logger
	) {
	}

	public function deactivate( int $userId, int $performerId ): DeactivationResult {
		$member = $this->members->getMember( $userId, ReadConsistency::UpToDate );

		if ( $member === null ) {
			return DeactivationResult::NotAMember;
		}

		if ( !$this->blocker->blockMember( $userId, $performerId ) ) {
			$this->logger->error( 'Member not deactivated: the block could not be placed', [
				'email' => NormalizedEmail::hashOf( $member->email )
			] );

			return DeactivationResult::BlockFailed;
		}

		$this->members->deactivateMember( $userId );

		$this->logger->info( 'Member deactivated', [
			'email' => NormalizedEmail::hashOf( $member->email ),
			'performer' => $performerId
		] );

		return DeactivationResult::Deactivated;
	}

}
