<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\REST;

use MediaWiki\Rest\Response;
use MediaWiki\Session\CsrfTokenSet;
use MediaWiki\User\ActorNormalization;
use ProfessionalWiki\MemberAccess\Application\AddEntryOutcome;
use ProfessionalWiki\MemberAccess\Application\AddEntryResult;
use ProfessionalWiki\MemberAccess\Application\AddEntryUseCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use Wikimedia\Rdbms\IConnectionProvider;

class AddEntryApi extends MemberAccessApiHandler {

	public function __construct(
		CsrfTokenSet $csrfTokens,
		private readonly AddEntryUseCase $useCase,
		private readonly ActorNormalization $actors,
		private readonly IConnectionProvider $connectionProvider
	) {
		parent::__construct( $csrfTokens );
	}

	public function run( int $id ): Response {
		$result = $this->useCase->addEntry( $id, $this->bodyString( 'value' ), $this->actorId() );

		return match ( $result->outcome ) {
			AddEntryOutcome::Added => $this->newAddedResponse( $result, $id ),
			AddEntryOutcome::InvalidValue => $this->newErrorResponse(
				'invalid_entry_value',
				'An entry is either an email address or "@" followed by a domain',
				400
			),
			AddEntryOutcome::ValueTooLong => $this->newErrorResponse(
				'entry_value_too_long',
				'An entry may be at most ' . AllowlistValue::MAX_LENGTH . ' bytes long',
				400
			),
			AddEntryOutcome::GroupNotFound => $this->newErrorResponse(
				'group_not_found',
				'There is no group with that id',
				404
			),
			AddEntryOutcome::DuplicateValue => $this->newDuplicateResponse( $result )
		};
	}

	private function newAddedResponse( AddEntryResult $result, int $groupId ): Response {
		$entry = $result->entry;

		return $this->newJsonResponse( [
			'id' => $entry?->id,
			'groupId' => $groupId,
			'value' => $entry?->value->value,
			'kind' => $entry?->value->kind->value,
			'created' => self::toIso8601( $entry?->creationTimestamp )
		] );
	}

	private function newDuplicateResponse( AddEntryResult $result ): Response {
		return $this->newErrorResponse(
			'duplicate_entry',
			'Another group already admits that address or domain',
			409,
			[
				'conflictingGroupId' => $result->conflictingGroup?->id,
				'conflictingGroupName' => $result->conflictingGroup?->name
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
