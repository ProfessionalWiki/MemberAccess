<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use MediaWiki\RenameUser\RenameuserSQL;
use MediaWiki\User\User;
use ProfessionalWiki\MemberAccess\Application\OpaqueUsername;
use ProfessionalWiki\MemberAccess\Application\UsernameMinter;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\ILBFactory;
use Wikimedia\Rdbms\LikeValue;

/**
 * Gives an opaque name to every member the extension did not name, which is what members were
 * before it started minting names for them.
 *
 * Run by update.php, and safe to run again: a wiki whose members are all named after nobody has
 * nothing here to rename.
 *
 * The roster is what a member is, so every account it names and the extension did not is renamed.
 * The reader group catches the ones the roster has forgotten, whose account a removal left behind,
 * and those are recognised by the address in the name: a wiki may point the group at one it already
 * had, holding staff this update has no business renaming. Every other account on the wiki is
 * somebody else's to name.
 */
class OpaqueNameUpdate {

	private const string RENAME_REASON_MESSAGE = 'memberaccess-opaque-name-reason';

	/**
	 * Who the rename is recorded as having been performed by. Stolen if an account of that name
	 * already exists, since the update has to run whatever else the wiki called it.
	 */
	private const string SYSTEM_USER = 'MemberAccess';

	private const string MEMBER_TABLE = 'memberaccess_member';

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly ILBFactory $loadBalancers,
		private readonly UsernameMinter $minter,
		private readonly LoggerInterface $logger,
		private readonly string $readerGroup
	) {
	}

	/**
	 * @return int How many accounts were renamed
	 */
	public function run(): int {
		$accounts = $this->accountsWhoseNameIdentifiesTheirHolder();

		if ( $accounts === [] ) {
			return 0;
		}

		$performer = User::newSystemUser( self::SYSTEM_USER, [ 'steal' => true ] );

		if ( $performer === null ) {
			$this->logger->error(
				'Members were not given opaque names: there is no account to record the renames as'
			);

			return 0;
		}

		return $this->rename( $accounts, $performer );
	}

	/**
	 * One account at a time, so that a wiki with thousands of members does not hand its replicas a
	 * single burst, and so that one account that cannot be renamed leaves the rest to be renamed
	 * rather than taking update.php down with it.
	 *
	 * @param array<int, string> $accounts The current name per user id
	 * @return int How many were renamed
	 */
	private function rename( array $accounts, User $performer ): int {
		$renamed = 0;

		foreach ( $accounts as $userId => $name ) {
			$renamed += $this->giveAnOpaqueName( $userId, $name, $performer ) ? 1 : 0;
			$this->loadBalancers->waitForReplication();
		}

		$left = count( $accounts ) - $renamed;

		if ( $left > 0 ) {
			$this->logger->error( 'Members were left under a name that identifies them', [ 'accounts' => $left ] );
		}

		return $renamed;
	}

	/**
	 * Both halves are read off the small tables, so no wiki has its user table read whole for this.
	 *
	 * @return array<int, string> The current name per user id
	 */
	private function accountsWhoseNameIdentifiesTheirHolder(): array {
		return $this->membersTheExtensionDidNotName() + $this->forgottenAccountsNamedAfterAnAddress();
	}

	/**
	 * Every member on the roster whose name the extension did not mint. Judged by the shape of the
	 * name rather than by an address in it, since a member admitted through single sign-on carries
	 * whatever their identity provider called them, which names them without holding an address.
	 *
	 * @return array<int, string> The current name per user id
	 */
	private function membersTheExtensionDidNotName(): array {
		$database = $this->connectionProvider->getPrimaryDatabase();

		$accounts = $this->namesById( $database->newSelectQueryBuilder()
			->select( [ 'user_id', 'user_name' ] )
			->from( self::MEMBER_TABLE )
			->join( 'user', null, 'user_id = mam_user_id' )
			->caller( __METHOD__ )
			->fetchResultSet() );

		return array_filter(
			$accounts,
			static fn ( string $name ): bool => !OpaqueUsername::isOpaque( $name )
		);
	}

	/**
	 * Every account carrying the reader group that the roster does not name, and that is named
	 * after an address. The group alone would also catch the staff of a wiki that pointed
	 * $wgMemberAccessReaderGroup at a group it already had.
	 *
	 * @return array<int, string> The current name per user id
	 */
	private function forgottenAccountsNamedAfterAnAddress(): array {
		$database = $this->connectionProvider->getPrimaryDatabase();

		return $this->namesById( $database->newSelectQueryBuilder()
			->select( [ 'user_id', 'user_name' ] )
			->from( 'user_groups' )
			->join( 'user', null, 'user_id = ug_user' )
			->leftJoin( self::MEMBER_TABLE, null, 'mam_user_id = user_id' )
			->where( [ 'ug_group' => $this->readerGroup ] )
			->andWhere( $database->expr( 'mam_user_id', '=', null ) )
			->andWhere( $database->expr(
				'user_name',
				IExpression::LIKE,
				new LikeValue( $database->anyString(), '@', $database->anyString() )
			) )
			->caller( __METHOD__ )
			->fetchResultSet() );
	}

	/**
	 * @param iterable<mixed> $rows
	 * @return array<int, string> The current name per user id
	 */
	private function namesById( iterable $rows ): array {
		$accounts = [];

		foreach ( $rows as $row ) {
			if ( $row instanceof stdClass ) {
				$accounts[(int)$row->user_id] = strval( $row->user_name );
			}
		}

		return $accounts;
	}

	/**
	 * Through the same code Special:RenameUser renames with, so that the name goes from everything
	 * holding a copy of it: the actor table, the account's own log entries, and any block placed on
	 * it. A member can change nothing on the wiki, so there is nothing else of theirs to
	 * re-attribute.
	 *
	 * Recorded by user id alone, and a failure by the class of the failure alone. The name is what
	 * this update exists to take out of view, and what a failure from MediaWiki names: a database
	 * error carries the statement it failed on, and that statement carries the name.
	 */
	private function giveAnOpaqueName( int $userId, string $currentName, User $performer ): bool {
		$database = $this->connectionProvider->getPrimaryDatabase();
		$section = $database->startAtomic( __METHOD__, IDatabase::ATOMIC_CANCELABLE );

		try {
			$renamed = ( new RenameuserSQL(
				$currentName,
				$this->minter->mintUsername(),
				$userId,
				$performer,
				[ 'reason' => wfMessage( self::RENAME_REASON_MESSAGE )->inContentLanguage()->text() ]
			) )->rename();
		} catch ( Throwable $failure ) {
			// Core's rename opens an atomic section that stays open when a failure escapes it,
			// which would take down or silently roll back every rename after this one. Cancelling
			// this outer section discards the dangling one with it.
			$database->cancelAtomic( __METHOD__, $section );

			$this->logger->error( 'Member account not renamed', [
				'user' => $userId,
				'reason' => get_class( $failure )
			] );

			return false;
		}

		$database->endAtomic( __METHOD__ );

		if ( $renamed ) {
			$this->logger->info( 'Member account given an opaque name', [ 'user' => $userId ] );

			return true;
		}

		$this->logger->error( 'Member account not renamed', [ 'user' => $userId ] );

		return false;
	}

}
