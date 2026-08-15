<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * A named set of allowlist entries, used to attribute members to the party that vouched for them.
 */
final class MemberGroup {

	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $creationTimestamp
	) {
	}

}
