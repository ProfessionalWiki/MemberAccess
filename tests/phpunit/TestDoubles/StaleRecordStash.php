<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * A stash still holding records in a shape an earlier version of the extension wrote.
 */
class StaleRecordStash extends HashBagOStuff {

	public function __construct(
		private readonly mixed $staleRecord = [ 'address' => 'jane@example.com' ]
	) {
		parent::__construct();
	}

	/**
	 * @param string $key
	 * @param int $flags
	 * @return mixed
	 */
	public function get( $key, $flags = 0 ) {
		return $this->staleRecord;
	}

}
