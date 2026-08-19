<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\REST;

use MediaWiki\Rest\Response;
use MediaWiki\Session\CsrfTokenSet;
use ProfessionalWiki\MemberAccess\Application\RemovalResult;
use ProfessionalWiki\MemberAccess\Application\RemoveMemberUseCase;
use ProfessionalWiki\MemberAccess\Application\Schema;

class RemoveMemberApi extends MemberAccessApiHandler {

	public function __construct(
		CsrfTokenSet $csrfTokens,
		Schema $schema,
		private readonly RemoveMemberUseCase $useCase
	) {
		parent::__construct( $csrfTokens, $schema );
	}

	public function run( int $userId ): Response {
		return $this->refuse( $userId ) ?? $this->remove( $userId );
	}

	/**
	 * Removing renames the account and drops its sessions, which done to your own account is a
	 * locked door with the key on the inside.
	 */
	private function refuse( int $userId ): ?Response {
		if ( $userId === $this->performerId() ) {
			return $this->newErrorResponse(
				'cannot_remove_self',
				'Removing your own account would take your access with it',
				409
			);
		}

		return null;
	}

	private function remove( int $userId ): Response {
		return match ( $this->useCase->remove( $userId, $this->performerId() ) ) {
			RemovalResult::Removed => $this->newJsonResponse( [
				'userId' => $userId,
				'removed' => true
			] ),
			RemovalResult::NotAMember => $this->newErrorResponse(
				'not_a_member',
				'That account was not admitted through the allowlist',
				404
			),
			RemovalResult::ReservedNameTaken => $this->newErrorResponse(
				'reserved_name_taken',
				'The name a removed member\'s account is parked under is held by another account',
				409
			),
			RemovalResult::RemovalFailed => $this->newErrorResponse(
				'removal_failed',
				'The member was left as they were because their account could not be renamed',
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
