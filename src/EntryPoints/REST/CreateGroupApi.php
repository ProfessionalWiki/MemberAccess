<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\REST;

use MediaWiki\Rest\Response;
use MediaWiki\Session\CsrfTokenSet;
use ProfessionalWiki\MemberAccess\Application\CreateGroupOutcome;
use ProfessionalWiki\MemberAccess\Application\CreateGroupUseCase;
use ProfessionalWiki\MemberAccess\Application\MemberGroup;

class CreateGroupApi extends MemberAccessApiHandler {

	public function __construct(
		CsrfTokenSet $csrfTokens,
		private readonly CreateGroupUseCase $useCase
	) {
		parent::__construct( $csrfTokens );
	}

	public function run(): Response {
		$result = $this->useCase->createGroup( $this->bodyString( 'name' ) );

		return match ( $result->outcome ) {
			CreateGroupOutcome::Created => $this->newJsonResponse( [
				'id' => $result->group?->id,
				'name' => $result->group?->name,
				'created' => self::toIso8601( $result->group?->creationTimestamp )
			] ),
			CreateGroupOutcome::InvalidName => $this->newErrorResponse(
				'invalid_group_name',
				'A group needs a name',
				400
			),
			CreateGroupOutcome::NameTooLong => $this->newErrorResponse(
				'group_name_too_long',
				'A group name may be at most ' . MemberGroup::MAX_NAME_LENGTH . ' bytes long',
				400
			),
			CreateGroupOutcome::DuplicateName => $this->newErrorResponse(
				'duplicate_group_name',
				'Another group already has that name',
				409
			)
		};
	}

}
