<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * What one allowlist entry admits: a single address, or every address at one domain.
 */
final class AllowlistValue {

	/**
	 * As many bytes as the column that stores it holds.
	 */
	public const int MAX_LENGTH = 255;

	private const string DOMAIN_PREFIX = '@';

	private function __construct(
		public readonly string $value,
		public readonly EntryKind $kind
	) {
	}

	public static function fromString( string $input ): ?self {
		$normalized = mb_strtolower( self::trimmed( $input ) );

		if ( self::exceedsMaxLength( $normalized ) || self::hasSpaceOrHiddenCharacter( $normalized ) ) {
			return null;
		}

		if ( !str_starts_with( $normalized, self::DOMAIN_PREFIX ) ) {
			$email = NormalizedEmail::fromString( $normalized );

			return $email === null ? null : self::forEmail( $email );
		}

		return self::forDomain( substr( $normalized, strlen( self::DOMAIN_PREFIX ) ) );
	}

	public static function exceedsMaxLength( string $input ): bool {
		return strlen( self::trimmed( $input ) ) > self::MAX_LENGTH;
	}

	/**
	 * Trims every kind of space, not only the ASCII ones. A pasted entry often arrives with a
	 * non-breaking space in front, which would otherwise make "@example.com" an address entry that
	 * matches nothing.
	 */
	private static function trimmed( string $input ): string {
		return preg_replace( '/^[\pZ\s]+|[\pZ\s]+$/u', '', $input ) ?? trim( $input );
	}

	/**
	 * Any kind of space, and any character that leaves no trace on screen, so that an entry cannot
	 * carry something its reader will not see. Encoding the match cannot read counts as one.
	 */
	private static function hasSpaceOrHiddenCharacter( string $value ): bool {
		return preg_match( '/[\p{C}\pZ\s]/u', $value ) !== 0;
	}

	private static function forDomain( string $domain ): ?self {
		if ( $domain === '' || preg_match( '/[\s@]/', $domain ) === 1 ) {
			return null;
		}

		return new self( self::DOMAIN_PREFIX . $domain, EntryKind::Domain );
	}

	public static function forEmail( NormalizedEmail $email ): self {
		return new self( $email->value, EntryKind::Email );
	}

	public static function forDomainOf( NormalizedEmail $email ): self {
		return new self( self::DOMAIN_PREFIX . $email->domain(), EntryKind::Domain );
	}

	/**
	 * Rebuilds a value that was normalized before it was stored.
	 */
	public static function fromStorage( string $value, EntryKind $kind ): self {
		return new self( $value, $kind );
	}

	/**
	 * The one address this admits, or null when it admits a whole domain and so names nobody to
	 * write to.
	 */
	public function asEmail(): ?NormalizedEmail {
		return $this->kind === EntryKind::Email ? NormalizedEmail::fromString( $this->value ) : null;
	}

}
