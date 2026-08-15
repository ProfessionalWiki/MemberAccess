<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

interface MemberRepository {

	public function recordMember( int $userId, NormalizedEmail $email, int $groupId ): void;

	public function getMember( int $userId, ReadConsistency $consistency ): ?Member;

	public function findMemberByEmail( NormalizedEmail $email ): ?Member;

	/**
	 * Notes that the member logged in. Does nothing when the account is no member.
	 */
	public function recordLogin( int $userId ): void;

	/**
	 * @return Member[] Ordered by creation, oldest first
	 */
	public function listMembers(): array;

	public function getTotals(): MemberTotals;

	public function deactivateMember( int $userId ): void;

	public function reactivateMember( int $userId ): void;

}
