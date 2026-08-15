<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\REST;

use MediaWiki\Rest\Response;
use MediaWiki\Session\CsrfTokenSet;
use ProfessionalWiki\MemberAccess\Application\ReactivateMemberUseCase;
use ProfessionalWiki\MemberAccess\Application\ReactivationResult;

class ReactivateMemberApi extends MemberAccessApiHandler {

	public function __construct(
		CsrfTokenSet $csrfTokens,
		private readonly ReactivateMemberUseCase $useCase
	) {
		parent::__construct( $csrfTokens );
	}

	public function run( int $userId ): Response {
		return match ( $this->useCase->reactivate( $userId, $this->performerId() ) ) {
			ReactivationResult::Reactivated => $this->newJsonResponse( [
				'userId' => $userId,
				'active' => true,
				'blocked' => false
			] ),
			ReactivationResult::ReactivatedButStillBlocked => $this->newJsonResponse( [
				'userId' => $userId,
				'active' => true,
				'blocked' => true
			] ),
			ReactivationResult::NotAMember => $this->newErrorResponse(
				'not_a_member',
				'That account was not admitted through the allowlist',
				404
			),
			ReactivationResult::UnblockFailed => $this->newErrorResponse(
				'unblock_failed',
				'The member was left deactivated because the block could not be lifted',
				500
			)
		};
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function getParamSettings(): array {
		return self::idPathParam( 'userId' );
	}

}
