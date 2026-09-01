<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

use Psr\Log\LoggerInterface;

/**
 * Removes a member: the roster forgets them and their account gives up their address, so the
 * address can be admitted again and arrive at a new account.
 *
 * Deactivation ends a member's access while keeping who they were, which is why a deactivated
 * member can still be removed. Removal is the act that forgets them.
 */
class RemoveMemberUseCase {

	public function __construct(
		private readonly MemberRepository $members,
		private readonly MemberRemover $remover,
		private readonly LoggerInterface $logger
	) {
	}

	public function remove( int $userId, int $performerId ): RemovalResult {
		$member = $this->members->getMember( $userId, ReadConsistency::UpToDate );

		if ( $member === null ) {
			return RemovalResult::NotAMember;
		}

		$this->remover->removeMember( $userId );

		$this->logger->info( 'Member removed', [
			'email' => NormalizedEmail::hashOf( $member->email ),
			'performer' => $performerId
		] );

		return RemovalResult::Removed;
	}

}
