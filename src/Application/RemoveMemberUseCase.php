<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

use Psr\Log\LoggerInterface;

/**
 * Removes a member: the roster forgets them and their account stops holding the username their
 * address maps to, so the address can be admitted again and arrive at a new account.
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

		$result = $this->remover->removeMember( $userId, $performerId );

		$this->record( $result, $member, $performerId );

		return $result;
	}

	private function record( RemovalResult $result, Member $member, int $performerId ): void {
		if ( $result === RemovalResult::Removed ) {
			$this->logger->info( 'Member removed', [
				'email' => NormalizedEmail::hashOf( $member->email ),
				'performer' => $performerId
			] );

			return;
		}

		$this->logger->error( 'Member not removed', [
			'email' => NormalizedEmail::hashOf( $member->email ),
			'performer' => $performerId,
			'reason' => $result->name
		] );
	}

}
