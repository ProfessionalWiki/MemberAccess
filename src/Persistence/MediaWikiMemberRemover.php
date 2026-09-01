<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use MediaWiki\Session\SessionManager;
use MediaWiki\User\UserFactory;
use ProfessionalWiki\MemberAccess\Application\MemberRemover;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use Throwable;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IDBAccessObject;

class MediaWikiMemberRemover implements MemberRemover {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly MemberRepository $members,
		private readonly UserFactory $userFactory,
		private readonly SessionManager $sessions
	) {
	}

	/**
	 * The two database writes are one atomic section, so a removal that cannot finish leaves neither
	 * behind. A forgotten roster row on its own would be the worse half: nothing of this extension
	 * refuses the account a password reset anymore, and the reset finds accounts by their address,
	 * so the address left behind would mail the removed member a way back into an account that
	 * still carries the reader group.
	 *
	 * Both reach the primary database through this connection provider, which is what puts them in
	 * the section: the repository shares it, and the account asks the services for the same handle.
	 * Dropping the sessions is in the section too: invalidating them writes the account's token
	 * through that same handle, so a removal that cannot finish takes the logout back with the rest.
	 */
	public function removeMember( int $userId ): void {
		$database = $this->connectionProvider->getPrimaryDatabase();
		$section = $database->startAtomic( __METHOD__, IDatabase::ATOMIC_CANCELABLE );

		try {
			$this->members->forgetMember( $userId );
			$this->closeTheAccount( $userId );
		} catch ( Throwable $failure ) {
			// The REST framework answers the rethrown failure with an error response, after which
			// the request's transaction round commits as usual: without this cancel, the forgotten
			// row would be committed without the address having gone with it.
			$database->cancelAtomic( __METHOD__, $section );

			throw $failure;
		}

		$database->endAtomic( __METHOD__ );
	}

	/**
	 * The address goes, so that the account the removal leaves behind holds nothing of the member:
	 * no password, no address to mail one to, and no roster row for a login to arrive at. The
	 * sessions go with it, since a remembered login would otherwise keep a removed member reading
	 * for about a month.
	 *
	 * A roster row naming an account that is no longer there has nothing left to close, and is
	 * forgotten on its own.
	 */
	private function closeTheAccount( int $userId ): void {
		$account = $this->userFactory->newFromId( $userId );
		$account->load( IDBAccessObject::READ_LATEST );

		if ( !$account->isRegistered() ) {
			return;
		}

		$account->invalidateEmail();
		$account->saveSettings();

		$this->sessions->invalidateSessionsForUser( $account );
	}

}
