<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use ProfessionalWiki\MemberAccess\Application\Member;
use ProfessionalWiki\MemberAccess\Application\MemberCount;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use ProfessionalWiki\MemberAccess\Application\MemberTotals;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;

class InMemoryMemberRepository implements MemberRepository {

	private const string CREATION_TIMESTAMP = '20260101000000';
	private const string EVENT_TIMESTAMP = '20260202000000';

	/**
	 * @var array<int, Member>
	 */
	private array $members = [];

	private int $addressLookups = 0;

	public function recordMember( int $userId, NormalizedEmail $email, ?int $groupId ): void {
		$this->members[$userId] = new Member(
			userId: $userId,
			email: $email->value,
			groupId: $groupId,
			creationTimestamp: self::CREATION_TIMESTAMP,
			deactivationTimestamp: null,
			lastLoginTimestamp: null
		);
	}

	public function getMember( int $userId, ReadConsistency $consistency = ReadConsistency::UpToDate ): ?Member {
		return $this->members[$userId] ?? null;
	}

	public function findMemberByEmail( NormalizedEmail $email ): ?Member {
		$this->addressLookups++;

		foreach ( $this->members as $member ) {
			if ( $member->email === $email->value ) {
				return $member;
			}
		}

		return null;
	}

	/**
	 * How often the roster was asked about an address, for callers that have to do the same work
	 * whatever the answer.
	 */
	public function getAddressLookupCount(): int {
		return $this->addressLookups;
	}

	public function listMembers(): array {
		return array_values( $this->members );
	}

	public function getTotals(): MemberTotals {
		$perGroup = [];

		foreach ( $this->members as $member ) {
			if ( $member->groupId === null ) {
				continue;
			}

			$count = $perGroup[$member->groupId] ?? new MemberCount( all: 0, active: 0 );
			$perGroup[$member->groupId] = new MemberCount(
				all: $count->all + 1,
				active: $count->active + ( $member->isActive() ? 1 : 0 )
			);
		}

		return new MemberTotals(
			overall: new MemberCount(
				all: count( $this->members ),
				active: count( array_filter(
					$this->members,
					static fn ( Member $member ): bool => $member->isActive()
				) )
			),
			perGroup: $perGroup
		);
	}

	public function deactivateMember( int $userId ): void {
		$this->replace( $userId, deactivation: self::EVENT_TIMESTAMP );
	}

	public function reactivateMember( int $userId ): void {
		$this->replace( $userId, deactivation: null );
	}

	public function recordLogin( int $userId ): void {
		$member = $this->members[$userId] ?? null;

		if ( $member !== null ) {
			$this->replace( $userId, deactivation: $member->deactivationTimestamp, login: self::EVENT_TIMESTAMP );
		}
	}

	private function replace( int $userId, ?string $deactivation, ?string $login = null ): void {
		$member = $this->members[$userId] ?? null;

		if ( $member === null ) {
			return;
		}

		$this->members[$userId] = new Member(
			userId: $member->userId,
			email: $member->email,
			groupId: $member->groupId,
			creationTimestamp: $member->creationTimestamp,
			deactivationTimestamp: $deactivation,
			lastLoginTimestamp: $login ?? $member->lastLoginTimestamp
		);
	}

}
