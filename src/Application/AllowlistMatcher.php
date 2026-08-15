<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * Decides whether an email address is admitted, and by which group.
 */
class AllowlistMatcher {

	public function __construct(
		private readonly AllowlistRepository $allowlist
	) {
	}

	public function matchEmail( string $email ): ?MemberGroup {
		$normalized = NormalizedEmail::fromString( $email );

		if ( $normalized === null ) {
			return null;
		}

		return $this->match( $normalized );
	}

	public function match( NormalizedEmail $email ): ?MemberGroup {
		return $this->allowlist->findGroupForValue( AllowlistValue::forEmail( $email ) )
			?? $this->allowlist->findGroupForValue( AllowlistValue::forDomainOf( $email ) );
	}

}
