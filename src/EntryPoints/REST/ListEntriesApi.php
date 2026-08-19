<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\REST;

use MediaWiki\Rest\Response;
use MediaWiki\Session\CsrfTokenSet;
use ProfessionalWiki\MemberAccess\Application\AllowlistEntry;
use ProfessionalWiki\MemberAccess\Application\AllowlistRepository;
use ProfessionalWiki\MemberAccess\Application\MemberGroupRepository;
use ProfessionalWiki\MemberAccess\Application\Schema;

class ListEntriesApi extends MemberAccessApiHandler {

	public function __construct(
		CsrfTokenSet $csrfTokens,
		Schema $schema,
		private readonly MemberGroupRepository $groups,
		private readonly AllowlistRepository $allowlist
	) {
		parent::__construct( $csrfTokens, $schema );
	}

	public function run( int $id ): Response {
		if ( $this->groups->getGroup( $id ) === null ) {
			return $this->newErrorResponse( 'group_not_found', 'There is no group with that id', 404 );
		}

		return $this->newJsonResponse( [
			'groupId' => $id,
			'entries' => array_map(
				static fn ( AllowlistEntry $entry ): array => [
					'id' => $entry->id,
					'value' => $entry->value->value,
					'kind' => $entry->value->kind->value,
					'created' => self::toIso8601( $entry->creationTimestamp )
				],
				$this->allowlist->listEntries( $id )
			)
		] );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function getParamSettings(): array {
		return self::idPathParam( 'id' );
	}

	public function needsWriteAccess(): bool {
		return false;
	}

}
