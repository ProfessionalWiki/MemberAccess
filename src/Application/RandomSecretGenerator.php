<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

class RandomSecretGenerator implements SecretGenerator {

	private const int CODE_DIGITS = 8;
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
