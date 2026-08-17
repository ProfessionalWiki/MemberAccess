<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

final class MemberTotals {

	/**
	 * Members that no group admitted are in the overall count and in no per-group one.
	 *
	 * @param array<int, MemberCount> $perGroup Keyed by group id, only groups that have members
	 */
	public function __construct(
		public readonly MemberCount $overall,
		public readonly array $perGroup
	) {
	}

	public function forGroup( int $groupId ): MemberCount {
		return $this->perGroup[$groupId] ?? new MemberCount( all: 0, active: 0 );
	}

}
