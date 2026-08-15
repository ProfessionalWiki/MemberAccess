<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

final class AddEntryResult {

	private function __construct(
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

	public static function added( AllowlistEntry $entry ): self {
		return new self( AddEntryOutcome::Added, $entry, null );
	}

	public static function invalidValue(): self {
		return new self( AddEntryOutcome::InvalidValue, null, null );
	}

	public static function valueTooLong(): self {
		return new self( AddEntryOutcome::ValueTooLong, null, null );
	}

	public static function groupNotFound(): self {
		return new self( AddEntryOutcome::GroupNotFound, null, null );
	}

	public static function duplicateValue( ?MemberGroup $conflictingGroup ): self {
		return new self( AddEntryOutcome::DuplicateValue, null, $conflictingGroup );
	}

}
