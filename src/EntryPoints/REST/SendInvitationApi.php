<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\REST;

use MediaWiki\Rest\RequestInterface;
use MediaWiki\Rest\Response;
use MediaWiki\Session\CsrfTokenSet;
use ProfessionalWiki\MemberAccess\Application\InvitationOutcome;
use ProfessionalWiki\MemberAccess\Application\SendInvitationUseCase;

class SendInvitationApi extends MemberAccessApiHandler {

	public function __construct(
		CsrfTokenSet $csrfTokens,
		private readonly SendInvitationUseCase $useCase
	) {
		parent::__construct( $csrfTokens );
	}

	public function run( int $id ): Response {
		$result = $this->useCase->sendInvitation( $id, $this->performerId() );

		return match ( $result->outcome ) {
			InvitationOutcome::Sent => $this->newJsonResponse( [
				'id' => $id,
				'invited' => self::toIso8601( $result->invitationTimestamp )
			] ),
			InvitationOutcome::EntryNotFound => $this->newErrorResponse(
				'entry_not_found',
				'There is no allowlist entry with that id',
				404
			),
			InvitationOutcome::NotAnAddress => $this->newErrorResponse(
				'not_an_address',
				'That entry admits a whole domain, so there is no address to invite',
				400
			),
			InvitationOutcome::CodeLoginOff => $this->newErrorResponse(
				'code_login_off',
				'An invitation asks its reader to log in with a code, which this wiki does not offer',
				409
			),
			InvitationOutcome::SendFailed => $this->newErrorResponse(
				'invitation_not_sent',
				'The invitation could not be sent, and nothing was recorded',
				500
			)
		};
	}

	/**
	 * The endpoint takes no body, so one that is empty is not a malformed request, whatever content
	 * type it announces: a browser posting nothing still sends the type it was configured with.
	 *
	 * @return array<mixed>
	 */
	public function parseBodyData( RequestInterface $request ): ?array {
		return $request->getBody()->getSize() === 0 ? [] : parent::parseBodyData( $request );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function getParamSettings(): array {
		return self::idPathParam( 'id' );
	}

}
