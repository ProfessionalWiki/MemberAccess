<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use ProfessionalWiki\MemberAccess\Application\InvitationMailer;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;

class SpyInvitationMailer implements InvitationMailer {

	/**
	 * @var string[]
	 */
	private array $invitedAddresses = [];

	public function __construct(
		private readonly bool $sendSucceeds = true
	) {
	}

	public function sendInvitation( NormalizedEmail $email ): bool {
		$this->invitedAddresses[] = $email->value;

		return $this->sendSucceeds;
	}

	/**
	 * @return string[] One per invitation attempted, in the order they were made
	 */
	public function getInvitedAddresses(): array {
		return $this->invitedAddresses;
	}

}
