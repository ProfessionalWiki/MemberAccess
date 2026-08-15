<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use ProfessionalWiki\MemberAccess\Application\BlockLiftResult;
use ProfessionalWiki\MemberAccess\Application\MemberBlocker;

class SpyMemberBlocker implements MemberBlocker {

	/**
	 * @var array<int, int> Performer id per blocked user id
	 */
	private array $blocked = [];

	/**
	 * @var array<int, int> Performer id per unblocked user id
	 */
	private array $unblocked = [];

	public function __construct(
		private readonly bool $blockSucceeds = true,
		private readonly BlockLiftResult $liftResult = BlockLiftResult::Lifted
	) {
	}

	public function blockMember( int $userId, int $performerId ): bool {
		$this->blocked[$userId] = $performerId;

		return $this->blockSucceeds;
	}

	public function unblockMember( int $userId, int $performerId ): BlockLiftResult {
		$this->unblocked[$userId] = $performerId;

		return $this->liftResult;
	}

	public function performerWhoBlocked( int $userId ): ?int {
		return $this->blocked[$userId] ?? null;
	}

	public function performerWhoUnblocked( int $userId ): ?int {
		return $this->unblocked[$userId] ?? null;
	}

}
