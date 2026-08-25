<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\REST;

use MediaWiki\Rest\Response;
use MediaWiki\Session\CsrfTokenSet;
use ProfessionalWiki\MemberAccess\Application\DeleteGroupResult;
use ProfessionalWiki\MemberAccess\Application\DeleteGroupUseCase;

class DeleteGroupApi extends MemberAccessApiHandler {

	public function __construct(
		CsrfTokenSet $csrfTokens,
		private readonly DeleteGroupUseCase $useCase
	) {
		parent::__construct( $csrfTokens );
	}

	public function run( int $id ): Response {
		return match ( $this->useCase->deleteGroup( $id ) ) {
			DeleteGroupResult::Deleted => $this->newJsonResponse( [ 'id' => $id, 'deleted' => true ] ),
			DeleteGroupResult::GroupNotFound => $this->newErrorResponse(
				'group_not_found',
				'There is no group with that id',
				404
			),
			DeleteGroupResult::GroupHasMembers => $this->newErrorResponse(
				'group_has_members',
				'Members admitted through this group are still attributed to it',
				409
			),
			DeleteGroupResult::GroupNotEmpty => $this->newErrorResponse(
				'group_not_empty',
				'Remove the group\'s allowlist entries before deleting it',
				409
			)
		};
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function getParamSettings(): array {
		return self::idPathParam( 'id' );
	}

}
