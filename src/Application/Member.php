<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * An admitted account. The group is the one that admitted it, and is absent for a member the
 * allowlist did not admit, which the open code login route lets in.
 */
final class Member {

	public function __construct(
		public readonly int $userId,
		public readonly string $email,
		public readonly ?int $groupId,
		public readonly string $creationTimestamp,
		public readonly ?string $deactivationTimestamp,
		public readonly ?string $lastLoginTimestamp
	) {
	}

	public function isActive(): bool {
		return $this->deactivationTimestamp === null;
	}

}
