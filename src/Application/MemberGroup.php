<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * A named set of allowlist entries, used to attribute members to the party that vouched for them.
 */
final class MemberGroup {

	/**
	 * As many bytes as the column that stores it holds.
	 */
	public const int MAX_NAME_LENGTH = 255;

	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $creationTimestamp
	) {
	}

	/**
	 * Measured in bytes, the way the column is, so a name of few enough characters can still be
	 * too long.
	 */
	public static function nameExceedsMaxLength( string $name ): bool {
		return strlen( $name ) > self::MAX_NAME_LENGTH;
	}

}
