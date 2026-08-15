<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * How long an issued login code stays usable. Both the mail and the screen that asks for the code
 * tell the visitor how long they have, so the two say the same thing.
 */
final class CodeLifetime {

	private const int SECONDS_PER_MINUTE = 60;

	public function __construct(
		public readonly int $inSeconds
	) {
	}

	/**
	 * Whole minutes, and never fewer than one, since that is what the visitor is told.
	 */
	public function inMinutes(): int {
		return max( 1, intdiv( $this->inSeconds, self::SECONDS_PER_MINUTE ) );
	}

}
