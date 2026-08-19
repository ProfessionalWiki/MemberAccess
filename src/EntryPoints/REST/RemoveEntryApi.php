<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\REST;

use MediaWiki\Rest\Response;
use MediaWiki\Session\CsrfTokenSet;
use ProfessionalWiki\MemberAccess\Application\AllowlistRepository;
use ProfessionalWiki\MemberAccess\Application\Schema;

class RemoveEntryApi extends MemberAccessApiHandler {

	public function __construct(
		CsrfTokenSet $csrfTokens,
		Schema $schema,
		private readonly AllowlistRepository $allowlist
	) {
		parent::__construct( $csrfTokens, $schema );
	}

	public function run( int $id ): Response {
		if ( $this->allowlist->getEntry( $id ) === null ) {
			return $this->newErrorResponse( 'entry_not_found', 'There is no allowlist entry with that id', 404 );
		}

		$this->allowlist->removeEntry( $id );

		return $this->newJsonResponse( [ 'id' => $id, 'deleted' => true ] );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function getParamSettings(): array {
		return self::idPathParam( 'id' );
	}

}
