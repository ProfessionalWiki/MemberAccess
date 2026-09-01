<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use ProfessionalWiki\MemberAccess\Application\MemberRemover;

class SpyMemberRemover implements MemberRemover {

	/**
	 * @var int[] The user ids that were removed
	 */
	private array $removed = [];

	public function removeMember( int $userId ): void {
		$this->removed[] = $userId;
	}

	public function hasRemoved( int $userId ): bool {
		return in_array( $userId, $this->removed, true );
	}

}
