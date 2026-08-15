<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\REST;

use MediaWiki\Rest\Response;
use MediaWiki\Session\CsrfTokenSet;
use ProfessionalWiki\MemberAccess\Application\DeactivateMemberUseCase;
use ProfessionalWiki\MemberAccess\Application\DeactivationResult;

class DeactivateMemberApi extends MemberAccessApiHandler {

	private const string BLOCK_RIGHT = 'block';

	public function __construct(
		CsrfTokenSet $csrfTokens,
		private readonly DeactivateMemberUseCase $useCase
	) {
		parent::__construct( $csrfTokens );
	}

	public function run( int $userId ): Response {
		return $this->refuse( $userId ) ?? $this->deactivate( $userId );
	}

	/**
	 * Deactivating places a block, which on a wiki where a block stops the account logging in makes
	 * deactivating yourself a locked door with the key on the inside.
	 */
	private function refuse( int $userId ): ?Response {
		if ( $userId === $this->performerId() ) {
			return $this->newErrorResponse(
				'cannot_deactivate_self',
				'Deactivating your own account would lock you out of the wiki',
				409
			);
		}

		if ( !$this->getAuthority()->isAllowed( self::BLOCK_RIGHT ) ) {
			return $this->newErrorResponse(
				'block_right_required',
				'Deactivating a member requires the "' . self::BLOCK_RIGHT . '" right',
				403
			);
		}

		return null;
	}

	private function deactivate( int $userId ): Response {
		return match ( $this->useCase->deactivate( $userId, $this->performerId() ) ) {
			DeactivationResult::Deactivated => $this->newJsonResponse( [
				'userId' => $userId,
				'active' => false
			] ),
			DeactivationResult::NotAMember => $this->newErrorResponse(
				'not_a_member',
				'That account was not admitted through the allowlist',
				404
			),
			DeactivationResult::BlockFailed => $this->newErrorResponse(
				'block_failed',
				'The member was left active because the block could not be placed',
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
