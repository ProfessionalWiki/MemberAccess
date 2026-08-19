<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use ProfessionalWiki\MemberAccess\Application\Schema;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IMaintainableDatabase;

/**
 * Asks the database whether the tables are there, once, and remembers the answer for the rest of
 * the request. Everything the extension does asks before touching a table, so the answer has to
 * cost at most one query, and one that cannot fail on a wiki without the tables.
 */
class DatabaseSchema implements Schema {

	/**
	 * update.php creates the three tables together, so one of them answers for all of them.
	 */
	private const string MEMBER_TABLE = 'memberaccess_member';

	private ?bool $missing = null;

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly LoggerInterface $logger
	) {
	}

	public function isMissing(): bool {
		$this->missing ??= $this->probe();

		return $this->missing;
	}

	private function probe(): bool {
		$missing = !$this->tablesExist();

		if ( $missing ) {
			$this->logger->warning(
				'The MemberAccess tables do not exist, so the extension does nothing. '
					. 'Run update.php to create them.'
			);
		}

		return $missing;
	}

	private function tablesExist(): bool {
		$database = $this->connectionProvider->getReplicaDatabase();

		if ( $database instanceof IMaintainableDatabase ) {
			return $database->tableExists( self::MEMBER_TABLE, __METHOD__ );
		}

		// Every connection MediaWiki hands out can answer this. One that cannot is no reason to
		// turn the extension off wiki-wide, so the wiki is taken to have its tables.
		return true;
	}

}
