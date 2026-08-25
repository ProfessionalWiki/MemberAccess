<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\REST;

use MediaWiki\Rest\Response;
use MediaWiki\Session\CsrfTokenSet;
use MediaWiki\User\ActorNormalization;
use ProfessionalWiki\MemberAccess\Application\AddEntriesOutcome;
use ProfessionalWiki\MemberAccess\Application\AddEntriesResult;
use ProfessionalWiki\MemberAccess\Application\AddEntriesUseCase;
use ProfessionalWiki\MemberAccess\Application\AddEntryOutcome;
use ProfessionalWiki\MemberAccess\Application\AddEntryResult;
use ProfessionalWiki\MemberAccess\Application\AllowlistEntry;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Adds a batch of addresses and domains to one group, the way an administrator pastes a list of
 * people to admit.
 *
 * What one value did concerns that value alone, so a refused value is reported next to the ones
 * that were added rather than as the answer to the request. What concerns the request as a whole —
 * who is calling, which group, how much at once — is answered as a failed request, and then nothing
 * is added.
 */
class AddEntriesApi extends MemberAccessApiHandler {

	public function __construct(
		CsrfTokenSet $csrfTokens,
		private readonly AddEntriesUseCase $useCase,
		private readonly ActorNormalization $actors,
		private readonly IConnectionProvider $connectionProvider
	) {
		parent::__construct( $csrfTokens );
	}

	public function run( int $id ): Response {
		$values = $this->requestedValues();

		if ( $values === null ) {
			return $this->newErrorResponse(
				'invalid_request_body',
				'The body needs a "values" list of addresses and domains',
				400
			);
		}

		$result = $this->useCase->addEntries( $id, $values, $this->actorId() );

		return match ( $result->outcome ) {
			AddEntriesOutcome::Processed => $this->newResultsResponse( $result ),
			AddEntriesOutcome::GroupNotFound => $this->newErrorResponse(
				'group_not_found',
				'There is no group with that id',
				404
			),
			AddEntriesOutcome::TooManyValues => $this->newErrorResponse(
				'too_many_entry_values',
				'A request may carry at most ' . AddEntriesUseCase::MAX_VALUES . ' values',
				400
			)
		};
	}

	/**
	 * @return string[]|null Null when the body carries no list of values
	 */
	private function requestedValues(): ?array {
		$values = $this->bodyData()['values'] ?? null;

		if ( !is_array( $values ) || !array_is_list( $values ) ) {
			return null;
		}

		$requested = [];

		foreach ( $values as $value ) {
			if ( !is_string( $value ) ) {
				return null;
			}

			$requested[] = $value;
		}

		return $requested;
	}

	private function newResultsResponse( AddEntriesResult $result ): Response {
		return $this->newJsonResponse( [
			'results' => array_map(
				fn ( AddEntryResult $entryResult ): array => $this->newResultBody( $entryResult ),
				$result->results
			)
		] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function newResultBody( AddEntryResult $result ): array {
		return [ 'value' => $result->value ] + match ( $result->outcome ) {
			AddEntryOutcome::Added => $this->newAddedBody( $result->entry ),
			AddEntryOutcome::InvalidValue => $this->newRefusalBody(
				'invalid_entry_value',
				'An entry is either an email address or "@" followed by a domain'
			),
			AddEntryOutcome::ValueTooLong => $this->newRefusalBody(
				'entry_value_too_long',
				'An entry may be at most ' . AllowlistValue::MAX_LENGTH . ' bytes long'
			),
			AddEntryOutcome::DuplicateValue => $this->newDuplicateBody( $result->conflictingGroup )
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function newAddedBody( ?AllowlistEntry $entry ): array {
		return [
			'added' => true,
			'entry' => [
				'id' => $entry?->id,
				'value' => $entry?->value->value,
				'kind' => $entry?->value->kind->value,
				'created' => self::toIso8601( $entry?->creationTimestamp )
			]
		];
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private function newRefusalBody( string $errorCode, string $message, array $extra = [] ): array {
		return [ 'added' => false ] + self::newErrorBody( $errorCode, $message, $extra );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function newDuplicateBody( ?MemberGroup $conflictingGroup ): array {
		return $this->newRefusalBody(
			'duplicate_entry',
			'A group already admits that address or domain',
			[
				'conflictingGroupId' => $conflictingGroup?->id,
				'conflictingGroupName' => $conflictingGroup?->name
			]
		);
	}

	/**
	 * The provenance of an entry is kept as an actor id, the way MediaWiki records who did
	 * something. Acquiring it creates the actor row for an admin who has none yet.
	 */
	private function actorId(): int {
		return $this->actors->acquireActorId(
			$this->getAuthority()->getUser(),
			$this->connectionProvider->getPrimaryDatabase()
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function getParamSettings(): array {
		return self::idPathParam( 'id' );
	}

}
