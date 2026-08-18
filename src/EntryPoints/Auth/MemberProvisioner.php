<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\Auth;

use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Turns a freshly created account into a member: reader group, roster row, confirmed address.
 *
 * Runs on account creation only, so an account that already existed keeps whatever groups it has.
 *
 * The member group is the allowlist group that admitted the account, and is absent when no entry
 * matched, which only the open code login route allows.
 */
class MemberProvisioner {

	public function __construct(
		private readonly MemberRepository $members,
		private readonly UserGroupManager $userGroups,
		private readonly LoggerInterface $logger,
		private readonly string $readerGroup
	) {
	}

	/**
	 * Stops at the first step that fails, so that no half-provisioned account is left behind. The
	 * reader group is where the revoked rights live, and another extension can refuse to add it, so
	 * an account that did not get it would be logged in with everything the group exists to take
	 * away. Without a roster row the account is also refused at every later login.
	 *
	 * @throws RuntimeException
	 */
	public function provision( User $user, NormalizedEmail $email, ?int $groupId ): void {
		if ( !$this->userGroups->addUserToGroup( $user, $this->readerGroup ) ) {
			$this->logger->error( 'Member account not created: the reader group could not be added', [
				'email' => $email->hash(),
				'group' => $groupId
			] );

			throw new RuntimeException( 'The reader group could not be added to a new member account' );
		}

		$this->members->recordMember( userId: $user->getId(), email: $email, groupId: $groupId );
		$this->confirmAddress( $user, $email );

		$this->logger->info( 'Member account created', [
			'email' => $email->hash(),
			'group' => $groupId
		] );
	}

	/**
	 * The one-time code, or the identity provider, already proved the visitor reads this mailbox.
	 */
	private function confirmAddress( User $user, NormalizedEmail $email ): void {
		$user->setEmail( $email->value );
		$user->confirmEmail();
		$user->saveSettings();
	}

}
