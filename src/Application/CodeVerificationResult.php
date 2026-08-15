<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

final class CodeVerificationResult {

	private function __construct(
		public readonly CodeVerificationOutcome $outcome,
		/**
		 * The address the code was issued for. Set only when the outcome is a pass.
		 */
		public readonly ?string $email,
		public readonly int $attemptsRemaining
	) {
	}

	public static function pass( string $email ): self {
		return new self( CodeVerificationOutcome::Pass, $email, 0 );
	}

	public static function retryableFailure( int $attemptsRemaining ): self {
		return new self( CodeVerificationOutcome::RetryableFailure, null, $attemptsRemaining );
	}

	public static function burned(): self {
		return new self( CodeVerificationOutcome::Burned, null, 0 );
	}

}
