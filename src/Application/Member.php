<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * An account admitted through the allowlist.
 */
final class Member {

	public function __construct(
		public readonly int $userId,
		public readonly string $email,
		public readonly int $groupId,
		public readonly string $creationTimestamp,
		public readonly ?string $deactivationTimestamp,
		public readonly ?string $lastLoginTimestamp
	) {
	}

	public function isActive(): bool {
		return $this->deactivationTimestamp === null;
	}

}
