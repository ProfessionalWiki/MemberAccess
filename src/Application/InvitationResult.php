<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

final class InvitationResult {

	private function __construct(
		public readonly InvitationOutcome $outcome,
		/**
		 * When the invitation was recorded as sent. Set only when one was.
		 */
		public readonly ?string $invitationTimestamp
	) {
	}

	public static function sent( string $invitationTimestamp ): self {
		return new self( InvitationOutcome::Sent, $invitationTimestamp );
	}

	public static function entryNotFound(): self {
		return new self( InvitationOutcome::EntryNotFound, null );
	}

	public static function notAnAddress(): self {
		return new self( InvitationOutcome::NotAnAddress, null );
	}

	public static function codeLoginOff(): self {
		return new self( InvitationOutcome::CodeLoginOff, null );
	}

	public static function sendFailed(): self {
		return new self( InvitationOutcome::SendFailed, null );
	}

}
