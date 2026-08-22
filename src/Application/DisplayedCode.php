<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * A code as it is shown to the member, and as it comes back.
 *
 * Shown in groups, so that it can be held in the head between the mail and the form. The grouping is
 * never checked: what a member types is put back the way it was issued before it is verified, so
 * copying the spaces along with the digits is not a wrong answer.
 * {@see \ProfessionalWiki\MemberAccess\Application\VerifyCodeUseCase}
 */
final class DisplayedCode {

	private const int GROUP_SIZE = 4;

	private function __construct() {
	}

	/**
	 * A code whose length is not a whole number of groups keeps the remainder as a shorter last
	 * group, since a code is a code however long the wiki's generator makes them.
	 */
	public static function grouped( string $code ): string {
		return $code === '' ? '' : implode( ' ', str_split( $code, self::GROUP_SIZE ) );
	}

	/**
	 * How much room a code of this many digits takes once grouped, which is how much a box
	 * collecting one has to allow: a member who copies a code out of the mail brings its spaces.
	 */
	public static function groupedLength( int $digits ): int {
		return strlen( self::grouped( str_repeat( '0', $digits ) ) );
	}

	/**
	 * The digits alone, whichever way they came back grouped. Every kind of space is taken out, not
	 * only the one that was put in, since what a mail client hands to a clipboard is its own affair.
	 */
	public static function ungrouped( string $code ): string {
		return preg_replace( '/\s+/u', '', $code ) ?? $code;
	}

}
