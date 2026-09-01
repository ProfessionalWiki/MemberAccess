<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints;

use Closure;
use ProfessionalWiki\MemberAccess\Application\CodeLoginMode;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;

/**
 * Applies the wiki-wide settings the feature needs. Some hold for as long as the extension is
 * loaded; the rest follow the login routes, so a wiki that offers none of them is left as it was.
 *
 * Settings that can be stated in extension.json are stated there. What is left here either depends
 * on configuration only known at load time, or is a core setting extension.json cannot express.
 */
class RegistrationHandler {

	private const DEFAULT_READER_GROUP = 'reader';

	private const MANAGE_RIGHT = 'memberaccess-manage';
	private const PUBLIC_LOG = '*';
	private const LOGS_THAT_RECORD_MEMBERS = [ 'newusers', 'block', 'renameuser' ];
	private const PER_ADDRESS_CAPTCHA_TRIGGER = 'badloginperuser';
	private const SSO_EMAIL_PROCESSOR_SETTING = 'wgOpenIDConnect_EmailProcessor';
	private const SSO_USERNAME_PROCESSOR_SETTING = 'wgOpenIDConnect_PreferredUsernameProcessor';

	/**
	 * The account the update that gives members opaque names records its renames as.
	 * {@see \ProfessionalWiki\MemberAccess\Persistence\OpaqueNameUpdate}
	 */
	private const SYSTEM_USER = 'MemberAccess';

	public static function onRegistration(): void {
		self::applyWhatMembersNeed();
		self::applyWhatTheLoginRoutesNeed();
	}

	/**
	 * What a wiki with members needs for as long as the extension is loaded: what a member may do,
	 * what is recorded about them, and an administrator's reach over their accounts. Taking every
	 * login route away leaves the members and everything recorded about them behind, so none of
	 * this may lapse with the routes.
	 */
	private static function applyWhatMembersNeed(): void {
		self::moveReaderRevocationsToTheConfiguredGroup();
		self::closeTheLogsThatRecordMembers();
		self::reserveTheAccountTheRenamesAreRecordedAs();

		// A deactivated member is blocked, and only this makes a block keep them out of a private wiki.
		$GLOBALS['wgBlockDisablesLogin'] = true;
	}

	/**
	 * The update that gives members opaque names takes the name over if the wiki has an account of
	 * it, since it has to run whatever else the wiki called that account. Reserving the name is what
	 * keeps a real account from being there to take over.
	 */
	private static function reserveTheAccountTheRenamesAreRecordedAs(): void {
		$reserved = self::globalArray( 'wgReservedUsernames' );

		if ( !in_array( self::SYSTEM_USER, $reserved, true ) ) {
			$reserved[] = self::SYSTEM_USER;
		}

		$GLOBALS['wgReservedUsernames'] = $reserved;
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
	 * Three core logs record members: the new user log, where every account creation is, the block
	 * log, where every deactivation is, and the rename log, which holds what members were called
	 * before the update that gave them opaque names. All are closed to everyone who cannot manage
	 * members, which also keeps them out of recent changes, since a restricted log type is never
	 * written there.
	 *
	 * A member's name says nothing about them, so what the first two give away is that somebody
	 * joined or was deactivated, and when. The rename log is the one that still names addresses.
	 *
	 * A wiki that restricted one of them further keeps its own setting.
	 */
	private static function closeTheLogsThatRecordMembers(): void {
		$restrictions = self::globalArray( 'wgLogRestrictions' );

		foreach ( self::LOGS_THAT_RECORD_MEMBERS as $logType ) {
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

		if ( self::allowlistAppliesToSso() ) {
			self::nameTheMembersSingleSignOnAdmits();
		}

		if ( $codeRouteIsOffered || self::allowlistAppliesToSso() ) {
			self::allowLoggingInToCreateTheAccount();
			self::keepMembersSignedIn();
		}
	}

	/**
	 * A single sign-on login the allowlist admits creates its account through the identity
	 * provider's plugin, which settles on the name before the extension is asked anything.
	 * OpenIDConnect offers a say over that name, taken here so that a member is named after nobody,
	 * and a say over the address, taken so that the extension judges the login by the address the
	 * plugin resolved.
	 *
	 * A processor the wiki configured itself is kept and run first, on both.
	 */
	private static function nameTheMembersSingleSignOnAdmits(): void {
		self::recordTheAddressSingleSignOnResolves();
		self::mintTheNameTheAccountIsCreatedUnder();
	}

	/**
	 * Whether a login is a member's is decided by the address OpenIDConnect resolved, which is not
	 * always in the token payloads a processor is handed: the plugin reads the claim, and falls back
	 * to the identity provider's userinfo endpoint for it. So it is taken where the plugin has it,
	 * from the processor it offers over that address, which it calls before asking what to name the
	 * account, within the one authenticate() call.
	 *
	 * What a processor the wiki configured itself returns is what both the plugin and the extension
	 * go on with. {@see \ProfessionalWiki\MemberAccess\EntryPoints\Auth\SsoUsernameProcessor}
	 */
	private static function recordTheAddressSingleSignOnResolves(): void {
		$wrapped = self::processorTheWikiConfigured( self::SSO_EMAIL_PROCESSOR_SETTING );

		$GLOBALS[self::SSO_EMAIL_PROCESSOR_SETTING] = static function (
			?string $email,
			array $attributes
		) use ( $wrapped ): ?string {
			$resolved = $wrapped === null ? $email : $wrapped( $email, $attributes );
			$address = is_string( $resolved ) ? $resolved : null;

			MemberAccessExtension::getInstance()->recordSsoAddress( $address );

			return $address;
		};
	}

	/**
	 * Built when the login arrives rather than here, since no services exist yet.
	 * {@see \ProfessionalWiki\MemberAccess\EntryPoints\Auth\SsoUsernameProcessor}
	 */
	private static function mintTheNameTheAccountIsCreatedUnder(): void {
		$wrapped = self::processorTheWikiConfigured( self::SSO_USERNAME_PROCESSOR_SETTING );

		$GLOBALS[self::SSO_USERNAME_PROCESSOR_SETTING] = static fn (
			?string $preferredUsername,
			array $attributes
		): ?string => MemberAccessExtension::newSsoUsernameProcessor( $wrapped )(
			$preferredUsername,
			$attributes
		);
	}

	private static function processorTheWikiConfigured( string $setting ): ?Closure {
		$configured = $GLOBALS[$setting] ?? null;

		return is_callable( $configured ) ? Closure::fromCallable( $configured ) : null;
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
