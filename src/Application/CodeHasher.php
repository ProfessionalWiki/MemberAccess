<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * Keeps issued codes out of the store in plain text. The secret makes the stored digest useless to
 * anyone who can read the store but does not have the wiki's secret key.
 */
class CodeHasher {

	public function __construct(
		private readonly string $secret
	) {
	}

	public function hash( string $code ): string {
		return hash_hmac( 'sha256', $code, $this->secret );
	}

	public function matches( string $code, string $hash ): bool {
		return hash_equals( $hash, $this->hash( $code ) );
	}

}
