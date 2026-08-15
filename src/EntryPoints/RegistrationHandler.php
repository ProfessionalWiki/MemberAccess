<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints;

/**
 * Applies the wiki-wide settings the feature needs. Loading the extension is the switch that turns
 * members-only access on, so none of this is further conditional.
 *
 * Settings that can be stated in extension.json are stated there. What is left here either depends
 * on configuration only known at load time, or is a core setting extension.json cannot express.
 */
class RegistrationHandler {

	private const DEFAULT_READER_GROUP = 'reader';

	private const ADDRESS_LIKE_USERRIGHTS_DELIMITER = '@';
	private const REPLACEMENT_USERRIGHTS_DELIMITER = '@@';
	private const MANAGE_RIGHT = 'memberaccess-manage';
	private const PUBLIC_LOG = '*';
	private const LOGS_THAT_NAME_MEMBERS = [ 'newusers', 'block' ];

	public static function onRegistration(): void {
		self::moveReaderRevocationsToTheConfiguredGroup();
		self::allowAddressesAsUsernames();
		self::allowLoggingInToCreateTheAccount();
		self::keepMembersSignedIn();
		self::closeTheLogsThatNameMembers();

		// A deactivated member is blocked, and only this makes a block keep them out of a private wiki.
		$GLOBALS['wgBlockDisablesLogin'] = true;
	}

	/**
	 * A member's username is their address, so the logs that name accounts name the whole roster:
	 * account creations in the new user log, deactivations in the block log. Both are closed to
	 * everyone who cannot manage members, which also keeps them out of recent changes, since a
	 * restricted log type is never written there.
	 *
	 * A wiki that restricted one of them further keeps its own setting.
	 */
	private static function closeTheLogsThatNameMembers(): void {
		$restrictions = self::globalArray( 'wgLogRestrictions' );

		foreach ( self::LOGS_THAT_NAME_MEMBERS as $logType ) {
			if ( ( $restrictions[$logType] ?? self::PUBLIC_LOG ) === self::PUBLIC_LOG ) {
				$restrictions[$logType] = self::MANAGE_RIGHT;
			}
		}

		$GLOBALS['wgLogRestrictions'] = $restrictions;
	}

	/**
	 * The revoked rights are declared in extension.json under the default group name, which is the
	 * one place they are listed. A wiki that renamed the group gets the same list under its name.
	 */
	private static function moveReaderRevocationsToTheConfiguredGroup(): void {
		$group = self::globalString( 'wgMemberAccessReaderGroup' );

		if ( $group === self::DEFAULT_READER_GROUP || $group === '' ) {
			return;
		}

		$revocations = self::globalArray( 'wgRevokePermissions' );
		$revocations[$group] = $revocations[self::DEFAULT_READER_GROUP] ?? [];
		unset( $revocations[self::DEFAULT_READER_GROUP] );

		$GLOBALS['wgRevokePermissions'] = $revocations;
	}

	/**
	 * A member's username is their email address, so "@" has to stop meaning something else.
	 */
	private static function allowAddressesAsUsernames(): void {
		$GLOBALS['wgInvalidUsernameCharacters'] = str_replace(
			'@',
			'',
			self::globalString( 'wgInvalidUsernameCharacters' )
		);

		// Special:UserRights reads "name@wiki" as an account on another wiki, which every member
		// name looks like. A doubled delimiter cannot occur in an address we accept.
		if ( self::globalString( 'wgUserrightsInterwikiDelimiter' ) === self::ADDRESS_LIKE_USERRIGHTS_DELIMITER ) {
			$GLOBALS['wgUserrightsInterwikiDelimiter'] = self::REPLACEMENT_USERRIGHTS_DELIMITER;
		}
	}

	/**
	 * Members never register: their account is created the first time they log in.
	 */
	private static function allowLoggingInToCreateTheAccount(): void {
		$GLOBALS['wgGroupPermissions'] = array_replace_recursive(
			self::globalArray( 'wgGroupPermissions' ),
			[ '*' => [ 'autocreateaccount' => true ] ]
		);
	}

	/**
	 * Members log in by fetching a code from their mailbox, which is too much to ask often, so
	 * their login is always a remembered one and remembered logins last as long as configured.
	 *
	 * This decides how long a remembered login lasts for everyone on the wiki, not only for members.
	 * The default is a month, where core leaves it at half a year.
	 */
	private static function keepMembersSignedIn(): void {
		$duration = self::globalInt( 'wgMemberAccessSessionDurationSeconds' );

		if ( $duration > 0 ) {
			$GLOBALS['wgExtendedLoginCookieExpiration'] = $duration;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function globalArray( string $name ): array {
		$value = $GLOBALS[$name] ?? null;

		return is_array( $value ) ? $value : [];
	}

	private static function globalString( string $name ): string {
		$value = $GLOBALS[$name] ?? null;

		return is_scalar( $value ) ? strval( $value ) : '';
	}

	private static function globalInt( string $name ): int {
		$value = $GLOBALS[$name] ?? null;

		return is_scalar( $value ) ? intval( $value ) : 0;
	}

}
