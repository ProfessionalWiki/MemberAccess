<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints;

use Closure;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserNamePrefixSearch;

/**
 * A username search that answers the reader group with nothing.
 *
 * The pages that complete a username are handed this in place of the wiki's own search, so that a
 * prefix names a member no account. They ask on behalf of the public rather than of the account
 * asking, so who asked is read from the request context. The search this wraps is asked rather than
 * the one it inherits, so that a wiki that wired its own keeps it.
 */
class RestrictedUserNamePrefixSearch extends UserNamePrefixSearch {

	/**
	 * The constructor it inherits is not called: every search is answered here or by the search
	 * this wraps, and an inherited method that did reach what that sets up fails rather than
	 * answering from the wiki's own tables.
	 *
	 * @param Closure(): UserIdentity $requestingUser
	 */
	// phpcs:ignore MediaWiki.Usage.MissingParentCall.MissingParentCall
	public function __construct(
		private readonly UserNamePrefixSearch $inner,
		private readonly UserGroupManager $userGroups,
		private readonly string $readerGroup,
		private readonly Closure $requestingUser
	) {
	}

	/**
	 * @param string|Authority $audience
	 * @return string[]
	 */
	public function search( $audience, string $search, int $limit, int $offset = 0 ): array {
		if ( $this->holdsTheReaderGroup( ( $this->requestingUser )() ) ) {
			return [];
		}

		return $this->inner->search( $audience, $search, $limit, $offset );
	}

	private function holdsTheReaderGroup( UserIdentity $user ): bool {
		return in_array( $this->readerGroup, $this->userGroups->getUserGroups( $user ), true );
	}

}
