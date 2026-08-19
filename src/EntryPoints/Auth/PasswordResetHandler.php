<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\Auth;

use MediaWiki\User\Hook\SpecialPasswordResetOnSubmitHook;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use ProfessionalWiki\MemberAccess\Application\Schema;
use Wikimedia\Message\MessageSpecifier;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Keeps the accounts that are refused a password out of a password reset, so that asking for one
 * says nothing about who is a member.
 *
 * A member has no password to reset, and refusing the request once it names one answers differently
 * than an address nobody has, which turns the reset form into a way of asking. Dropping them before
 * that leaves a request naming only members answering exactly as one naming nobody does.
 *
 * The refusal itself stays: it is what keeps a member from being mailed a temporary password by any
 * other route.
 */
class PasswordResetHandler implements SpecialPasswordResetOnSubmitHook {

	public function __construct(
		private readonly MemberRepository $members,
		private readonly UserGroupManager $userGroups,
		private readonly string $readerGroup,
		private readonly Schema $schema
	) {
	}

	/**
	 * @param User[] &$users
	 * @param array{Username:?string, Email:?string} $data
	 * @param string|array<mixed>|MessageSpecifier &$error
	 */
	public function onSpecialPasswordResetOnSubmit( &$users, $data, &$error ): void {
		$users = array_values(
			array_filter( $users, fn ( User $user ): bool => !$this->isRefusedAPassword( $user ) )
		);
	}

	/**
	 * The roster says who the members are. On a wiki whose tables are not there yet the reader
	 * group says it instead, the same way the provider that refuses the password reads it: nothing
	 * else is left to tell a member's account from staff's, and a reset that reached one would mail
	 * a temporary password into an account meant to have none.
	 */
	private function isRefusedAPassword( User $user ): bool {
		return $this->schema->isMissing()
			? $this->holdsTheReaderGroup( $user )
			: $this->isMember( $user );
	}

	private function holdsTheReaderGroup( User $user ): bool {
		return in_array(
			$this->readerGroup,
			$this->userGroups->getUserGroups( $user, IDBAccessObject::READ_LATEST ),
			true
		);
	}

	private function isMember( User $user ): bool {
		return $this->members->getMember( $user->getId(), ReadConsistency::UpToDate ) !== null;
	}

}
