<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Separate from the other hook handlers because LoadExtensionSchemaUpdates cannot have services injected.
 *
 * A table is only created where it is missing, so a column added to one later reaches the installs
 * that already have it through its patch and them alone. The patches come after the tables, so that
 * an install getting its tables for the first time is not asked to alter what was just created with
 * the column in it.
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

		$updater->addExtensionField(
			'memberaccess_entry',
			'mae_invited',
			$sqlDir . '/patch-memberaccess_entry-mae_invited.sql'
		);
	}

}
