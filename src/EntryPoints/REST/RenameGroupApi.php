<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\REST;

use MediaWiki\Rest\Response;
use MediaWiki\Session\CsrfTokenSet;
use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use ProfessionalWiki\MemberAccess\Application\RenameGroupResult;
use ProfessionalWiki\MemberAccess\Application\RenameGroupUseCase;

class RenameGroupApi extends MemberAccessApiHandler {

	public function __construct(
		CsrfTokenSet $csrfTokens,
		private readonly RenameGroupUseCase $useCase
	) {
		parent::__construct( $csrfTokens );
	}

	public function run( int $id ): Response {
		$name = trim( $this->bodyString( 'name' ) );

		return match ( $this->useCase->renameGroup( $id, $name ) ) {
			RenameGroupResult::Renamed => $this->newJsonResponse( [ 'id' => $id, 'name' => $name ] ),
			RenameGroupResult::InvalidName => $this->newErrorResponse(
				'invalid_group_name',
				'A group needs a name',
				400
			),
			RenameGroupResult::NameTooLong => $this->newErrorResponse(
				'group_name_too_long',
				'A group name may be at most ' . MemberGroup::MAX_NAME_LENGTH . ' bytes long',
				400
			),
			RenameGroupResult::DuplicateName => $this->newErrorResponse(
				'duplicate_group_name',
				'Another group already has that name',
				409
			),
			RenameGroupResult::GroupNotFound => $this->newErrorResponse(
				'group_not_found',
				'There is no group with that id',
				404
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
