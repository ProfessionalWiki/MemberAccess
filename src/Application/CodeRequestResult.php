<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

final class CodeRequestResult {

	private function __construct(
		public readonly CodeRequestOutcome $outcome,
		/**
		 * The handle the entered code is verified against. Set whenever the request was accepted.
		 */
		public readonly ?string $handle
	) {
	}

	public static function accepted( string $handle ): self {
		return new self( CodeRequestOutcome::Accepted, $handle );
	}

	public static function throttled(): self {
		return new self( CodeRequestOutcome::Throttled, null );
	}

	public static function invalidEmail(): self {
		return new self( CodeRequestOutcome::InvalidEmail, null );
	}

}
