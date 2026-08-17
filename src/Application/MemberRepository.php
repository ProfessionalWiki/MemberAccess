<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

interface MemberRepository {

	public function recordMember( int $userId, NormalizedEmail $email, ?int $groupId ): void;

	public function getMember( int $userId, ReadConsistency $consistency ): ?Member;

	public function findMemberByEmail( NormalizedEmail $email ): ?Member;

	/**
	 * Notes that the member logged in. Does nothing when the account is no member.
	 */
	public function recordLogin( int $userId ): void;

	/**
	 * Gives the group to a member that has none. A member that already has one keeps it, since
	 * the group that admitted them is what their attribution means. Does nothing when the
	 * account is no member.
	 */
	public function attributeToGroup( int $userId, int $groupId ): void;

	/**
	 * @return Member[] Ordered by creation, oldest first
	 */
	public function listMembers(): array;

	public function getTotals(): MemberTotals;

	public function deactivateMember( int $userId ): void;

	public function reactivateMember( int $userId ): void;

}
