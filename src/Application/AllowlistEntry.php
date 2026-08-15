<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

final class AllowlistEntry {

	public function __construct(
		public readonly int $id,
		public readonly int $groupId,
		public readonly AllowlistValue $value,
		public readonly int $actorId,
		public readonly string $creationTimestamp
	) {
	}

}
