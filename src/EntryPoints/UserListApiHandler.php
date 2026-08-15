<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMessage;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\Hook\ApiCheckCanExecuteHook;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;

/**
 * Keeps members from reading the wiki's list of accounts through the action API.
 *
 * Members are told nothing about each other beyond what they need, so the query modules whose
 * purpose is to enumerate accounts are closed to the reader group. Everything else stays open.
 *
 * ApiCheckCanExecute is the only extension point that can refuse a query before it runs, but it is
 * handed the action module rather than the submodules it will run, so the requested submodules are
 * read off the query itself. Hiding the matching special pages is a wiki configuration matter and
 * deliberately not done here.
 */
class UserListApiHandler implements ApiCheckCanExecuteHook {

	public function __construct(
		private readonly UserGroupManager $userGroups,
		private readonly string $readerGroup,
		/**
		 * @var string[] Names of query submodules the reader group may not use
		 */
		private readonly array $blockedModules
	) {
	}

	/**
	 * @param ApiBase $module
	 * @param UserIdentity $user
	 * @param string|array<mixed>|ApiMessage &$message
	 */
	public function onApiCheckCanExecute( $module, $user, &$message ): bool {
		if ( !$module instanceof ApiQuery ) {
			return true;
		}

		$blocked = $this->firstBlockedSubmodule( $module );

		if ( $blocked === null || !$this->isMember( $user ) ) {
			return true;
		}

		$message = new ApiMessage(
			[ 'memberaccess-api-module-denied', $blocked ],
			'memberaccess-module-denied'
		);

		return false;
	}

	private function firstBlockedSubmodule( ApiQuery $query ): ?string {
		foreach ( $this->requestedSubmodules( $query ) as $submodule ) {
			if ( in_array( $submodule, $this->blockedModules, true ) ) {
				return $submodule;
			}
		}

		return null;
	}

	/**
	 * The generator is not one of the query's own parameters: it belongs to the page set that
	 * feeds it, and is asked for separately.
	 *
	 * @return string[]
	 */
	private function requestedSubmodules( ApiQuery $query ): array {
		$params = $query->extractRequestParams();

		return array_merge(
			$this->asStrings( $params['list'] ?? null ),
			$this->asStrings( $params['prop'] ?? null ),
			$this->asStrings( $params['meta'] ?? null ),
			$this->asStrings( $query->getPageSet()->extractRequestParams()['generator'] ?? null )
		);
	}

	/**
	 * @return string[]
	 */
	private function asStrings( mixed $value ): array {
		$names = [];

		foreach ( is_array( $value ) ? $value : [ $value ] as $name ) {
			if ( is_string( $name ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	private function isMember( UserIdentity $user ): bool {
		return in_array( $this->readerGroup, $this->userGroups->getUserGroups( $user ), true );
	}

}
