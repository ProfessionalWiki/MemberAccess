<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * Whether the wiki has the tables the extension keeps its groups, allowlist and roster in.
 *
 * A wiki gets them by running update.php, which it can be loaded without having done. Asked where
 * reaching for a table anyway would break something that is not the extension's: the login hook,
 * which runs for every login on the wiki. Everywhere else such a wiki fails with a database error,
 * which is what says what is wrong with it.
 */
interface Schema {

	public function isMissing(): bool;

}
