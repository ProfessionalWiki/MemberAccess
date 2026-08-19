<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

final class AddEntryResult {

	private function __construct(
		/**
		 * The value this is about, as it was given rather than as it was stored, so that a refusal
		 * can be put back in front of whoever pasted it. A result is matched to its value by
		 * position, not by this.
		 */
		public readonly string $value,
		public readonly AddEntryOutcome $outcome,
		/**
		 * The new entry. Set when it was added.
		 */
		public readonly ?AllowlistEntry $entry,
		/**
		 * The group that already admits the value. Set when the value is a duplicate, unless the
		 * entry holding it disappeared in the meantime.
		 */
		public readonly ?MemberGroup $conflictingGroup
	) {
	}

	public static function added( string $value, AllowlistEntry $entry ): self {
		return new self( $value, AddEntryOutcome::Added, $entry, null );
	}

	public static function invalidValue( string $value ): self {
		return new self( $value, AddEntryOutcome::InvalidValue, null, null );
	}

	public static function valueTooLong( string $value ): self {
		return new self( $value, AddEntryOutcome::ValueTooLong, null, null );
	}

	public static function duplicateValue( string $value, ?MemberGroup $conflictingGroup ): self {
		return new self( $value, AddEntryOutcome::DuplicateValue, null, $conflictingGroup );
	}

}
