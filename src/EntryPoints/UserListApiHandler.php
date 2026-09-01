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
 * Members are told nothing about each other beyond what they need, so the modules whose purpose is
 * to enumerate accounts, and those that answer whether an account exists or what it is called, are
 * closed to the reader group. Everything else stays open.
 *
 * ApiCheckCanExecute is the only extension point that can refuse a module before it runs. It is
 * handed the action, which is the blocked module itself for a top-level action, but for a query is
 * handed the query rather than the submodules it will run, so those are read off the query itself.
 * The special pages that name the same accounts are closed by UserListSpecialPageHandler.
 */
class UserListApiHandler implements ApiCheckCanExecuteHook {

	public function __construct(
		private readonly UserGroupManager $userGroups,
		private readonly string $readerGroup,
		/**
		 * @var string[] Names of actions and query submodules the reader group may not use
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
		$blocked = $this->firstBlockedModule( $module );

		if ( $blocked === null || !$this->holdsTheReaderGroup( $user ) ) {
			return true;
		}

		$message = new ApiMessage(
			[ 'memberaccess-api-module-denied', $blocked ],
			'memberaccess-module-denied'
		);

		return false;
	}

	private function firstBlockedModule( ApiBase $module ): ?string {
		$action = $module->getModuleName();

		if ( in_array( $action, $this->blockedModules, true ) ) {
			return $action;
		}

		if ( $module instanceof ApiQuery ) {
			return $this->firstBlockedSubmodule( $module );
		}

		return null;
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

	private function holdsTheReaderGroup( UserIdentity $user ): bool {
		return in_array( $this->readerGroup, $this->userGroups->getUserGroups( $user ), true );
	}

}
