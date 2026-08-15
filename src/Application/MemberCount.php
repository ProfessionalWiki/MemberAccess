<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

final class MemberCount {

	public function __construct(
		public readonly int $all,
		public readonly int $active
	) {
	}

}
