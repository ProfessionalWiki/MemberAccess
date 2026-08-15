<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

use Psr\Log\LoggerInterface;

/**
 * Checks an entered code against the one issued under a handle. A code passes once and is burned
 * after the configured number of wrong entries.
 */
class VerifyCodeUseCase {

	public function __construct(
		private readonly CodeRepository $codes,
		private readonly CounterStore $counters,
		private readonly CodeHasher $hasher,
		private readonly LoggerInterface $logger,
		private readonly CodeLifetime $codeLifetime,
		private readonly int $attemptLimit
	) {
	}

	public function verify( string $handle, string $code ): CodeVerificationResult {
		$issued = $this->codes->get( $handle );

		if ( $issued === null ) {
			$this->logger->info( 'Login code entered for a code that is no longer available' );

			return CodeVerificationResult::burned();
		}

		$attempt = $this->counters->increment( 'attempts:' . $handle, $this->codeLifetime->inSeconds );

		// Attempts nobody counts are attempts without a cap, so the code goes rather than the cap.
		if ( $attempt === null ) {
			$this->burn( $handle );
			$this->logger->info( 'Login code burned: its attempts cannot be counted' );

			return CodeVerificationResult::burned();
		}

		if ( $this->hasher->matches( $code, $issued->codeHash ) ) {
			$this->burn( $handle );
			$this->logger->info( 'Login code accepted', [ 'email' => NormalizedEmail::hashOf( $issued->email ) ] );

			return CodeVerificationResult::pass( $issued->email );
		}

		if ( $attempt >= $this->attemptLimit ) {
			$this->burn( $handle );
			$this->logger->info( 'Login code burned after too many wrong entries', [
				'email' => NormalizedEmail::hashOf( $issued->email )
			] );

			return CodeVerificationResult::burned();
		}

		$this->logger->info( 'Wrong login code entered', [ 'email' => NormalizedEmail::hashOf( $issued->email ) ] );

		return CodeVerificationResult::retryableFailure( $this->attemptLimit - $attempt );
	}

	private function burn( string $handle ): void {
		$this->codes->delete( $handle );
	}

}
