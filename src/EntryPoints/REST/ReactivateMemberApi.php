<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\REST;

use MediaWiki\Rest\Response;
use MediaWiki\Session\CsrfTokenSet;
use ProfessionalWiki\MemberAccess\Application\ReactivateMemberUseCase;
use ProfessionalWiki\MemberAccess\Application\ReactivationResult;

class ReactivateMemberApi extends MemberAccessApiHandler {

	private const string BLOCK_RIGHT = 'block';

	public function __construct(
		CsrfTokenSet $csrfTokens,
		private readonly ReactivateMemberUseCase $useCase
	) {
		parent::__construct( $csrfTokens );
	}

	public function run( int $userId ): Response {
		return $this->refuse() ?? $this->reactivate( $userId );
	}

	/**
	 * Reactivating lifts the block, which core refuses to a caller who may not block. Left to
	 * surface as a failed unblock, a permission problem would answer as a server error.
	 */
	private function refuse(): ?Response {
		if ( !$this->getAuthority()->isAllowed( self::BLOCK_RIGHT ) ) {
			return $this->newErrorResponse(
				'block_right_required',
				'Reactivating a member requires the "' . self::BLOCK_RIGHT . '" right',
				403
			);
		}

		return null;
	}

	private function reactivate( int $userId ): Response {
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
