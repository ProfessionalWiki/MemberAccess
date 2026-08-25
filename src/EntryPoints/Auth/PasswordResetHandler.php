<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\Auth;

use MediaWiki\User\Hook\SpecialPasswordResetOnSubmitHook;
use MediaWiki\User\User;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use Wikimedia\Message\MessageSpecifier;

/**
 * Keeps members out of a password reset, so that asking for one says nothing about who is a member.
 *
 * A member has no password to reset, and refusing the request once it names one answers differently
 * than an address nobody has, which turns the reset form into a way of asking. Dropping members
 * before that leaves a request naming only members answering exactly as one naming nobody does.
 *
 * The refusal itself stays: it is what keeps a member from being mailed a temporary password by any
 * other route.
 */
class PasswordResetHandler implements SpecialPasswordResetOnSubmitHook {

	public function __construct(
		private readonly MemberRepository $members
	) {
	}

	/**
	 * @param User[] &$users
	 * @param array{Username:?string, Email:?string} $data
	 * @param string|array<mixed>|MessageSpecifier &$error
	 */
	public function onSpecialPasswordResetOnSubmit( &$users, $data, &$error ): void {
		$users = array_values(
			array_filter( $users, fn ( User $user ): bool => !$this->isMember( $user ) )
		);
	}

	private function isMember( User $user ): bool {
		return $this->members->getMember( $user->getId(), ReadConsistency::UpToDate ) !== null;
	}

}
