<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

interface InvitationMailer {

	/**
	 * @return bool Whether the mail was accepted for delivery
	 */
	public function sendInvitation( NormalizedEmail $email ): bool;

}
