<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use MediaWiki\RenameUser\RenameuserSQL;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentityLookup;
use ProfessionalWiki\MemberAccess\Application\MemberRemover;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use ProfessionalWiki\MemberAccess\Application\RemovalResult;
use Psr\Log\LoggerInterface;
use Throwable;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Frees the username through the same code Special:RenameUser renames with, so a removal is an
 * ordinary rename: it is recorded in the rename log and it drops the account's sessions, which a
 * remembered login would otherwise keep alive for about a month.
 *
 * A member can change nothing on the wiki, so a removal normally has nothing of theirs to
 * re-attribute and finishes where it is asked for.
 */
class MediaWikiMemberRemover implements MemberRemover {

	/**
	 * What a removed member's account is parked under, with the user id behind it, so that no two
	 * removals can want the same name. A member admitted over the code route can never hold one,
	 * their username being their address; one admitted through an identity provider is named
	 * whatever that provider settled on, so the name is checked rather than assumed to be free.
	 */
	private const string RESERVED_NAME_PREFIX = 'Removed member ';

	private const string RENAME_REASON_MESSAGE = 'memberaccess-rename-reason';

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly MemberRepository $members,
		private readonly UserFactory $userFactory,
		private readonly UserIdentityLookup $userLookup,
		private readonly LoggerInterface $logger
	) {
	}

	public function removeMember( int $userId, int $performerId ): RemovalResult {
		$account = $this->userLookup->getUserIdentityByUserId( $userId, IDBAccessObject::READ_LATEST );

		// A roster row naming an account that is no longer there is stuck the same way, and has
		// no username left to free.
		if ( $account === null ) {
			$this->members->forgetMember( $userId );

			return RemovalResult::Removed;
		}

		$reservedName = self::RESERVED_NAME_PREFIX . $userId;

		if ( $this->nameIsHeldByAnotherAccount( $reservedName, $userId ) ) {
			$this->logger->error( 'Member not removed: the name to park their account under is taken', [
				'user' => $userId
			] );

			return RemovalResult::ReservedNameTaken;
		}

		return $this->forgetAndRename( $account->getName(), $reservedName, $userId, $performerId );
	}

	/**
	 * An account already sitting on the name is only in the way when it is somebody else's: a
	 * member whose provider named them that keeps their own name and is removed like any other.
	 */
	private function nameIsHeldByAnotherAccount( string $name, int $userId ): bool {
		$account = $this->userLookup->getUserIdentityByName( $name, IDBAccessObject::READ_LATEST );

		return $account !== null && $account->isRegistered() && $account->getId() !== $userId;
	}

	/**
	 * The three writes are one atomic section, so a removal that cannot finish leaves nothing of
	 * itself behind: a roster row without its rename would hold the username for good, which is
	 * the state removing a member undoes. The rename goes last because it is the write that can
	 * refuse, and cancelling then takes the forgotten row and the stripped address back with it.
	 *
	 * All writes reach the primary database through this connection provider, which is what puts
	 * them in the section: the repository shares it, and RenameuserSQL and the account ask the
	 * services for the same handle.
	 */
	private function forgetAndRename(
		string $currentName,
		string $reservedName,
		int $userId,
		int $performerId
	): RemovalResult {
		$database = $this->connectionProvider->getPrimaryDatabase();
		$section = $database->startAtomic( __METHOD__, IDatabase::ATOMIC_CANCELABLE );

		try {
			$this->members->forgetMember( $userId );
			$this->stripAddress( $userId );
			$renamed = $this->newRename( $currentName, $reservedName, $userId, $performerId )->rename();
		} catch ( Throwable $failure ) {
			$database->cancelAtomic( __METHOD__, $section );

			throw $failure;
		}

		if ( !$renamed ) {
			$database->cancelAtomic( __METHOD__, $section );

			$this->logger->error( 'Member not removed: their account could not be renamed', [
				'user' => $userId
			] );

			return RemovalResult::RemovalFailed;
		}

		$database->endAtomic( __METHOD__ );

		return RemovalResult::Removed;
	}

	/**
	 * With the roster row gone, nothing of this extension refuses the parked account a password
	 * reset anymore, and the reset finds accounts by their address. Left in place, the confirmed
	 * address would mail the removed member a way back into an account that still carries the
	 * reader group. Stripped, the parked account has no password, no address to mail one to, and
	 * no roster row for a login to arrive at.
	 */
	private function stripAddress( int $userId ): void {
		$account = $this->userFactory->newFromId( $userId );
		$account->load( IDBAccessObject::READ_LATEST );
		$account->invalidateEmail();
		$account->saveSettings();
	}

	private function newRename(
		string $currentName,
		string $reservedName,
		int $userId,
		int $performerId
	): RenameuserSQL {
		return new RenameuserSQL(
			$currentName,
			$reservedName,
			$userId,
			$this->userFactory->newFromId( $performerId ),
			[ 'reason' => wfMessage( self::RENAME_REASON_MESSAGE )->inContentLanguage()->text() ]
		);
	}

}
