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

	/**
	 * Whitespace of any kind is refused, and so are bytes that are no text: MediaWiki folds the
	 * first away when it makes a username out of the address, and lowercasing replaces the second
	 * with a question mark. Either way two addresses that differ would arrive as one.
	 *
	 * The check comes before lowercasing, which is what would hide the bytes that are no text.
	 */
	public static function fromString( string $email ): ?self {
		$trimmed = trim( $email );

		// Anything but a zero: one is a match, and false is input that is not valid text.
		if ( preg_match( '/\s/u', $trimmed ) !== 0 ) {
			return null;
		}

		$normalized = mb_strtolower( $trimmed );

		[ $localPart, $domain ] = self::split( $normalized );

		if ( $localPart === '' || $domain === '' ) {
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
