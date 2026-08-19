<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use ProfessionalWiki\MemberAccess\Application\Schema;

/**
 * A wiki that has loaded the extension without running update.php.
 */
class MissingSchema implements Schema {

	public function isMissing(): bool {
		return true;
	}

}
