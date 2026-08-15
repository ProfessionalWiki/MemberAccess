<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Separate from the other hook handlers because LoadExtensionSchemaUpdates cannot have services injected.
 */
class SchemaChangesHandler implements LoadExtensionSchemaUpdatesHook {

	private const array TABLES = [
		'memberaccess_group',
		'memberaccess_entry',
		'memberaccess_member'
	];

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$sqlDir = __DIR__ . '/../../sql/' . $updater->getDB()->getType();

		foreach ( self::TABLES as $table ) {
			$updater->addExtensionTable( $table, $sqlDir . '/' . $table . '.sql' );
		}
	}

}
