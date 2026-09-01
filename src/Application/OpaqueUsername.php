<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * The name a member's account carries: a constant word and characters drawn at random, saying
 * nothing about who holds it.
 *
 * Everywhere MediaWiki names an account is a place a member could be recognised, and there are more
 * such places than can be closed one by one. A name that identifies nobody is what makes them all
 * harmless. The address stays on the account as its confirmed email and in the roster, which is
 * what the extension looks members up by.
 *
 * The alphabet leaves out the characters that are read wrongly when a name is copied by hand, and
 * is uppercase throughout, so that the name survives MediaWiki's own capitalisation unchanged. Six
 * characters is a billion names, which is what keeps two members from arriving at one.
 *
 * Knowing the shape is also how a name that was not made here is told apart, which is what the
 * single sign-on route refuses a member's account under.
 */
class OpaqueUsername implements UsernameGenerator {

	private const string PREFIX = 'Member ';
	private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	private const int LENGTH = 6;

	public function generateUsername(): string {
		$name = self::PREFIX;

		for ( $character = 0; $character < self::LENGTH; $character++ ) {
			$name .= self::ALPHABET[random_int( 0, strlen( self::ALPHABET ) - 1 )];
		}

		return $name;
	}

	/**
	 * The digits at the end are what a plugin making the name unique appends to it, which leaves a
	 * name that still identifies nobody. The end of the name is the end of the string and not a
	 * line break before it, since a name a plugin never canonicalised may hold one.
	 */
	public static function isOpaque( string $name ): bool {
		return preg_match( self::pattern(), $name ) === 1;
	}

	private static function pattern(): string {
		return '/^'
			. preg_quote( self::PREFIX, '/' )
			. '[' . preg_quote( self::ALPHABET, '/' ) . ']{' . self::LENGTH . '}'
			. '[0-9]*\z/';
	}

}
