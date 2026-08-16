<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\Auth;

use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;

/**
 * The account a login that has been admitted is to be turned into a member, and what it is to be
 * provisioned with, held in the authentication session for the moment between admitting the login
 * and the account existing.
 *
 * The name is the one whichever provider creates the account has settled on, which outside the
 * one-time code route is not the address.
 */
final class PendingProvisioning {

	public function __construct(
		public readonly string $username,
		public readonly NormalizedEmail $email,
		public readonly int $groupId
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toSessionData(): array {
		return [
			'username' => $this->username,
			'email' => $this->email->value,
			'groupId' => $this->groupId
		];
	}

	public static function fromSessionData( mixed $data ): ?self {
		if ( !is_array( $data ) ) {
			return null;
		}

		$username = $data['username'] ?? null;
		$email = $data['email'] ?? null;
		$groupId = $data['groupId'] ?? null;

		if ( !is_string( $username ) || !is_string( $email ) || !is_int( $groupId ) ) {
			return null;
		}

		$address = NormalizedEmail::fromString( $email );

		return $address === null ? null : new self( $username, $address, $groupId );
	}

}
