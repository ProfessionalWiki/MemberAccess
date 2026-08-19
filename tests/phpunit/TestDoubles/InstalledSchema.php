<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use ProfessionalWiki\MemberAccess\Application\Schema;

/**
 * A wiki that has run update.php.
 */
class InstalledSchema implements Schema {

	public function isMissing(): bool {
		return false;
	}

}
