<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use ProfessionalWiki\MemberAccess\Application\MemberRemover;
use ProfessionalWiki\MemberAccess\Application\RemovalResult;

class SpyMemberRemover implements MemberRemover {

	/**
	 * @var array<int, int> Performer id per removed user id
	 */
	private array $removed = [];

	public function __construct(
		private readonly RemovalResult $result = RemovalResult::Removed
	) {
	}

	public function removeMember( int $userId, int $performerId ): RemovalResult {
		$this->removed[$userId] = $performerId;

		return $this->result;
	}

	public function performerWhoRemoved( int $userId ): ?int {
		return $this->removed[$userId] ?? null;
	}

}
