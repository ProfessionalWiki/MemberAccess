<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

final class AddEntriesResult {

	private function __construct(
		public readonly AddEntriesOutcome $outcome,
		/**
		 * One result per value, in the order the values were given. Empty unless the batch was
		 * processed.
		 *
		 * @var AddEntryResult[]
		 */
		public readonly array $results
	) {
	}

	/**
	 * @param AddEntryResult[] $results
	 */
	public static function processed( array $results ): self {
		return new self( AddEntriesOutcome::Processed, $results );
	}

	public static function groupNotFound(): self {
		return new self( AddEntriesOutcome::GroupNotFound, [] );
	}

	public static function tooManyValues(): self {
		return new self( AddEntriesOutcome::TooManyValues, [] );
	}

}
