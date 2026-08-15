<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * An email address reduced to the form the allowlist and the roster store it in.
 */
final class NormalizedEmail {

	private const int HASH_LENGTH = 12;

	private function __construct(
		public readonly string $value
	) {
	}

	public static function fromString( string $email ): ?self {
		$normalized = mb_strtolower( trim( $email ) );

		[ $localPart, $domain ] = self::split( $normalized );

		if ( $localPart === '' || $domain === '' ) {
			return null;
		}

		if ( preg_match( '/\s/', $normalized ) === 1 ) {
			return null;
		}

		return new self( $normalized );
	}

	/**
	 * @return array{string, string} The local part and the domain, both empty when there is not exactly one "@"
	 */
	private static function split( string $email ): array {
		$parts = explode( '@', $email );

		if ( count( $parts ) !== 2 ) {
			return [ '', '' ];
		}

		return [ $parts[0], $parts[1] ];
	}

	public function domain(): string {
		return self::split( $this->value )[1];
	}

	/**
	 * A short digest that identifies the address in logs without disclosing it.
	 */
	public function hash(): string {
		return self::hashOf( $this->value );
	}

	public static function hashOf( string $email ): string {
		return substr( hash( 'sha256', $email ), 0, self::HASH_LENGTH );
	}

}
