<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * Whether the wiki has the tables the extension keeps its groups, allowlist and roster in.
 *
 * A wiki gets them by running update.php, which it can be loaded without having done. Everything
 * that would read or write them asks this first, since a feature with nowhere to keep its members
 * is not one that half works.
 */
interface Schema {

	public function isMissing(): bool;

}
