<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;

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

		$updater->addExtensionUpdate( [ [ self::class, 'giveMembersOpaqueNames' ] ] );
	}

	/**
	 * Members were once named after their address, which is a name no wiki has to allow anymore.
	 * This runs on every update, and has nothing to do on a wiki whose members are already named
	 * after nobody.
	 */
	public static function giveMembersOpaqueNames( DatabaseUpdater $updater ): void {
		$renamed = MemberAccessExtension::getInstance()->newOpaqueNameUpdate()->run();

		if ( $renamed > 0 ) {
			$updater->output( "...gave $renamed member accounts an opaque username.\n" );
		}
	}

}
