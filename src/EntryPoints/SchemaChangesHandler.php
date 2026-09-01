<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;

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
