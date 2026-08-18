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

	/**
	 * Whether the group still has a member attributed to it, active or not, read from the primary
	 * database with what it read held. Same reason as {@see AllowlistRepository::groupHasEntries}.
	 */
	public function groupHasMembers( int $groupId ): bool;

	/**
	 * Forgets the member. The account itself is left alone: freeing the username it holds is the
	 * other half of removing a member, and belongs to the remover.
	 */
	public function forgetMember( int $userId ): void;

	public function deactivateMember( int $userId ): void;

	public function reactivateMember( int $userId ): void;

}
