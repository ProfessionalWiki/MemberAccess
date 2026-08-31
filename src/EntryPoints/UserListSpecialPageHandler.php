<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints;

use MediaWiki\SpecialPage\Hook\SpecialPageBeforeExecuteHook;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use PermissionsError;

/**
 * Keeps members from reading the wiki's list of accounts through the special pages that name them.
 *
 * Members are told nothing about each other beyond what they need, so the special pages whose
 * purpose is to enumerate or resolve accounts are closed to the reader group, as the matching
 * action API modules are. Everything else stays open.
 *
 * SpecialPageBeforeExecute is fired by SpecialPage::run(), which both a request for the page and a
 * transclusion of it go through. The transclusion is refused to everyone rather than to the reader
 * group: under $wgMiserMode the page is executed as an anonymous user, so there is no reader to
 * check, and what a parse renders outlives the request that asked for it.
 */
class UserListSpecialPageHandler implements SpecialPageBeforeExecuteHook {

	public function __construct(
		private readonly UserGroupManager $userGroups,
		private readonly string $readerGroup,
		/**
		 * @var string[] Names of special pages the reader group may not open
		 */
		private readonly array $blockedPages
	) {
	}

	/**
	 * @param SpecialPage $special
	 * @param string|null $subPage
	 */
	public function onSpecialPageBeforeExecute( $special, $subPage ): bool {
		if ( !in_array( $special->getName(), $this->blockedPages, true ) ) {
			return true;
		}

		// Refusing by exception would end the parse of the page holding the transclusion, so the
		// inclusion is left empty instead.
		if ( $special->including() ) {
			return false;
		}

		if ( $this->holdsTheReaderGroup( $special->getUser() ) ) {
			throw new PermissionsError( null, [ 'memberaccess-special-page-denied' ] );
		}

		return true;
	}

	private function holdsTheReaderGroup( UserIdentity $user ): bool {
		return in_array( $this->readerGroup, $this->userGroups->getUserGroups( $user ), true );
	}

}
