<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use MediaWiki\User\UserNamePrefixSearch;

/**
 * Stands in for the wiki's own username search, so that what a wrapper hands it can be seen.
 *
 * The constructor it inherits is not called: nothing here reaches what that would set up.
 */
class SpyUserNamePrefixSearch extends UserNamePrefixSearch {

	/**
	 * @var array<int, mixed> What the last search was asked, in the order the arguments are taken
	 */
	public array $asked = [];

	/**
	 * @param string[] $names
	 */
	// phpcs:ignore MediaWiki.Usage.MissingParentCall.MissingParentCall
	public function __construct( private readonly array $names ) {
	}

	/**
	 * @param string|\MediaWiki\Permissions\Authority $audience
	 * @return string[]
	 */
	public function search( $audience, string $search, int $limit, int $offset = 0 ): array {
		$this->asked = [ $audience, $search, $limit, $offset ];

		return $this->names;
	}

}
