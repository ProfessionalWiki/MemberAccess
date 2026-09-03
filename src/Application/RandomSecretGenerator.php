<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

class RandomSecretGenerator implements SecretGenerator {

	/** Also how many digits the form asking for a code allows to be typed into it. */
	public const int CODE_DIGITS = 6;
	private const int HANDLE_BYTES = 16;

	public function generateCode(): string {
		return str_pad(
			(string)random_int( 0, 10 ** self::CODE_DIGITS - 1 ),
			self::CODE_DIGITS,
			'0',
			STR_PAD_LEFT
		);
	}

	public function generateHandle(): string {
		return bin2hex( random_bytes( self::HANDLE_BYTES ) );
	}

}
