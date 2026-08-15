<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\Auth;

use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;

/**
 * What a login that has been admitted needs turned into a member account, held in the
 * authentication session for the moment between admitting the login and the account existing.
 */
final class PendingProvisioning {

	public function __construct(
		public readonly NormalizedEmail $email,
		public readonly int $groupId
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toSessionData(): array {
		return [
			'email' => $this->email->value,
			'groupId' => $this->groupId
		];
	}

	public static function fromSessionData( mixed $data ): ?self {
		if ( !is_array( $data ) ) {
			return null;
		}

		$email = $data['email'] ?? null;
		$groupId = $data['groupId'] ?? null;

		if ( !is_string( $email ) || !is_int( $groupId ) ) {
			return null;
		}

		$address = NormalizedEmail::fromString( $email );

		return $address === null ? null : new self( $address, $groupId );
	}

}
