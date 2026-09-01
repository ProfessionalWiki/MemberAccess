<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserNamePrefixSearch;

/**
 * Stands in for the pages that complete a username: it declares the same dependency and asks it the
 * same question, so what the extension does for those pages is tested without naming one of them.
 */
class CompletionProbeSpecialPage extends SpecialPage {

	public function __construct( private readonly UserNamePrefixSearch $userNames ) {
		parent::__construct( 'CompletionProbe' );
	}

	public static function newFromUserNames( UserNamePrefixSearch $userNames ): self {
		return new self( $userNames );
	}

	/**
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return string[]
	 */
	public function prefixSearchSubpages( $search, $limit, $offset ) {
		return $this->userNames->search( UserNamePrefixSearch::AUDIENCE_PUBLIC, $search, $limit, $offset );
	}

}
