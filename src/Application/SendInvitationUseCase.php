<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

use Psr\Log\LoggerInterface;

/**
 * Tells one admitted address that it may now log in, and writes down when it was told.
 *
 * Admitting an address and inviting it are separate acts: an administrator preparing a group is not
 * yet announcing it, and an invitation that went missing has to be repeatable. So this is asked for
 * on its own, as often as it takes, and each time replaces what the last one wrote down.
 *
 * The mail goes out while the request is held, since the request is about one address and its
 * answer is whether that one mail went. What makes the code mail wait is that how long it takes
 * would otherwise say whether an address is on the list; here the caller already holds the right to
 * manage the list and named the entry it is asking about.
 */
class SendInvitationUseCase {

	public function __construct(
		private readonly CodeLoginMode $mode,
		private readonly AllowlistRepository $allowlist,
		private readonly InvitationMailer $mailer,
		private readonly LoggerInterface $logger
	) {
	}

	public function sendInvitation( int $entryId, int $performerId ): InvitationResult {
		$entry = $this->allowlist->getEntry( $entryId );

		if ( $entry === null ) {
			return InvitationResult::entryNotFound();
		}

		$email = $entry->value->asEmail();

		if ( $email === null ) {
			return InvitationResult::notAnAddress();
		}

		// The invitation tells its reader to ask for a login code, so a wiki that issues none has
		// nothing to invite anyone to.
		if ( $this->mode === CodeLoginMode::Off ) {
			return InvitationResult::codeLoginOff();
		}

		return $this->send( $entryId, $email, $performerId );
	}

	private function send( int $entryId, NormalizedEmail $email, int $performerId ): InvitationResult {
		if ( !$this->mailer->sendInvitation( $email ) ) {
			$this->logger->warning( 'Invitation not sent', [
				'email' => $email->hash(),
				'performer' => $performerId
			] );

			return InvitationResult::sendFailed();
		}

		$invitationTimestamp = $this->allowlist->recordInvitation( $entryId );

		$this->logger->info( 'Invitation sent', [
			'email' => $email->hash(),
			'performer' => $performerId
		] );

		return InvitationResult::sent( $invitationTimestamp );
	}

}
