<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints;

use ProfessionalWiki\MemberAccess\Application\CodeLoginMode;

/**
 * Applies the wiki-wide settings the feature needs. Some hold for as long as the extension is
 * loaded; the rest follow the login routes, so a wiki that offers none of them is left as it was.
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
	private const LOGS_THAT_NAME_MEMBERS = [ 'newusers', 'block', 'renameuser' ];
	private const PER_ADDRESS_CAPTCHA_TRIGGER = 'badloginperuser';

	public static function onRegistration(): void {
		self::applyWhatMembersNeed();
		self::applyWhatTheLoginRoutesNeed();
	}

	/**
	 * What a wiki with members needs for as long as the extension is loaded: what a member may do,
	 * what may name them, and an administrator's reach over their accounts. Taking every login route
	 * away leaves the members and everything recorded about them behind, so none of this may lapse
	 * with the routes.
	 */
	private static function applyWhatMembersNeed(): void {
		self::moveReaderRevocationsToTheConfiguredGroup();
		self::allowAddressesAsUsernames();
		self::closeTheLogsThatNameMembers();

		// A deactivated member is blocked, and only this makes a block keep them out of a private wiki.
		$GLOBALS['wgBlockDisablesLogin'] = true;

		// Completing a username answers by reader now, and this is what keeps the search box's
		// suggestions from being cached for everyone.
		$GLOBALS['wgSearchSuggestCacheExpiry'] = 0;
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
	 * A member's username is their address, so the logs that name accounts name the whole roster:
	 * account creations in the new user log, deactivations in the block log, and the address a
	 * removed member held in the rename log. All are closed to everyone who cannot manage members,
	 * which also keeps them out of recent changes, since a restricted log type is never written
	 * there.
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
	 * What a login route needs to work, applied only where a setting turns that route on. Each of
	 * these widens what the wiki allows or changes it for everyone on it, and the settings are read
	 * afresh on every request, so taking the last route away takes them with it.
	 */
	private static function applyWhatTheLoginRoutesNeed(): void {
		$codeRouteIsOffered = self::codeLoginMode() !== CodeLoginMode::Off;

		if ( $codeRouteIsOffered ) {
			self::keepTheCaptchaFromTellingMembersApart();
		}

		if ( $codeRouteIsOffered || self::allowlistAppliesToSso() ) {
			self::allowLoggingInToCreateTheAccount();
			self::keepMembersSignedIn();
		}
	}

	private static function codeLoginMode(): CodeLoginMode {
		return CodeLoginMode::fromSetting( self::globalString( 'wgMemberAccessCodeLogin' ) );
	}

	/**
	 * Members never register: their account is created the first time they log in, whichever
	 * member route that is. Both routes autocreate through AuthManager, which asks this right of
	 * the anonymous visitor logging in.
	 *
	 * This is the one thing here that widens what an anonymous visitor may do, so it follows the
	 * route settings exactly. Single sign-on outside the allowlist is plain PluggableAuth, and what
	 * that needs stays the wiki's to grant.
	 */
	private static function allowLoggingInToCreateTheAccount(): void {
		$GLOBALS['wgGroupPermissions'] = array_replace_recursive(
			self::globalArray( 'wgGroupPermissions' ),
			[ '*' => [ 'autocreateaccount' => true ] ]
		);
	}

	/**
	 * A code request names the address in the field MediaWiki reads the login subject from, which is
	 * also where ConfirmEdit looks before deciding whether to demand a captcha. Its per-address
	 * bad-login counter is filled by any failed login for the address, but emptied only by a
	 * successful one, and for a member's address the only login that can succeed is one the
	 * allowlist admitted. Whether a code request meets a captcha would then differ between an
	 * admitted address and one that is not, which is the one thing a code request must never say.
	 *
	 * ConfirmEdit gates filling, reading and emptying that counter on this trigger, so turning it
	 * off closes all three, at the price of the escalation on password logins to any account here.
	 * The per-IP counter beside it stays on: no login empties that one, so it says nothing about the
	 * address it was reached with.
	 *
	 * That price buys nothing where there is no code request to meet a captcha, which is why it is
	 * paid only while the code route is turned on.
	 */
	private static function keepTheCaptchaFromTellingMembersApart(): void {
		$triggers = self::globalArray( 'wgCaptchaTriggers' );
		$triggers[self::PER_ADDRESS_CAPTCHA_TRIGGER] = false;

		$GLOBALS['wgCaptchaTriggers'] = $triggers;
	}

	/**
	 * Only an explicit true holds single sign-on to the allowlist, and with it makes that route
	 * make members. Read as MemberAccessExtension reads it, from the global, since no services
	 * exist yet.
	 */
	private static function allowlistAppliesToSso(): bool {
		return ( $GLOBALS['wgMemberAccessApplyAllowlistToSso'] ?? null ) === true;
	}

	/**
	 * Members log in by fetching a code from their mailbox, which is too much to ask often, so
	 * their login is always a remembered one and remembered logins last as long as configured.
	 *
	 * This decides how long a remembered login lasts for everyone on the wiki, not only for members.
	 * The default is a month, where core leaves it at half a year. A wiki no route can log a member
	 * in to keeps what it had.
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
