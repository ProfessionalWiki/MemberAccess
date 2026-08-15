<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

final class IssuedCode {

	public function __construct(
		public readonly string $email,
		public readonly string $codeHash
	) {
	}

}
