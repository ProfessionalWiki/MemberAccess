<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

final class AllowlistEntry {

	public function __construct(
		public readonly int $id,
		public readonly int $groupId,
		public readonly AllowlistValue $value,
		public readonly int $actorId,
		public readonly string $creationTimestamp,
		/**
		 * When an invitation was last sent to the address. Null when none was, and always for a
		 * domain rule, which has nobody to invite.
		 */
		public readonly ?string $invitationTimestamp = null
	) {
	}

}
