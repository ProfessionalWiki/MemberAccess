<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\Permissions\Authority;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\EntryPoints\RegistrationHandler;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\RegistrationHandler
 */
class RegistrationHandlerTest extends MediaWikiIntegrationTestCase {

	private const REVOKED_FROM_READERS = [
		'edit',
		'createpage',
		'createtalk',
		'applychangetags',
		'changetags',
		'minoredit',
		'upload',
		'reupload',
		'reupload-own',
		'upload_by_url',
		'move',
		'movefile',
		'move-subpages',
		'delete',
		'undelete',
		'protect',
		'createaccount',
		'sendemail',
		'viewmyprivateinfo',
		'editmyprivateinfo',
		'editmyoptions',
		'cs-comment',
		'abusefilter-view',
		'abusefilter-log'
	];

	/**
	 * extension.json has to name the callback for any of what follows to reach a wiki. Nothing else
	 * takes "@" out of the invalid username characters.
	 */
	public function testLoadingTheExtensionAppliesItsSettings(): void {
		$this->assertStringNotContainsString( '@', $GLOBALS['wgInvalidUsernameCharacters'] );
	}

	/**
	 * @dataProvider revokedRightProvider
	 */
	public function testReadersLoseTheRightsThatWouldLetThemChangeTheWiki( string $right ): void {
		$this->assertTrue( $GLOBALS['wgRevokePermissions']['reader'][$right] ?? false, $right );
	}

	public static function revokedRightProvider(): iterable {
		foreach ( self::REVOKED_FROM_READERS as $right ) {
			yield $right => [ $right ];
		}
	}

	/**
	 * CommentStreams grants commenting to every group that may edit, unless some group declares
	 * the right itself, so the reader group has to declare it away.
	 */
	public function testReaderCannotCommentEvenWhereEveryoneElseMay(): void {
		$this->setGroupPermissions( 'user', 'cs-comment', true );

		$this->assertFalse( $this->reader()->isAllowed( 'cs-comment' ) );
	}

	/**
	 * The right that opens Special:ChangeEmail, and with it the address the member is known by.
	 */
	public function testReaderCannotReadTheirOwnPrivateInformation(): void {
		$this->assertFalse( $this->reader()->isAllowed( 'viewmyprivateinfo' ) );
	}

	/**
	 * @dataProvider everyRouteStateProvider
	 */
	public function testRenamingTheReaderGroupMovesTheRevokedRightsWithIt(
		string $codeLogin,
		bool $allowlistAppliesToSso
	): void {
		$this->setMwGlobals( [
			'wgMemberAccessReaderGroup' => 'members',
			'wgRevokePermissions' => [ 'reader' => [ 'edit' => true ] ]
		] );

		$this->registerWithRoutes( $codeLogin, $allowlistAppliesToSso );

		$this->assertSame( [ 'members' => [ 'edit' => true ] ], $GLOBALS['wgRevokePermissions'] );
	}

	/**
	 * @dataProvider everyRouteStateProvider
	 */
	public function testBlockingAMemberKeepsThemOutOfAPrivateWiki(
		string $codeLogin,
		bool $allowlistAppliesToSso
	): void {
		$this->setMwGlobals( 'wgBlockDisablesLogin', false );

		$this->registerWithRoutes( $codeLogin, $allowlistAppliesToSso );

		$this->assertTrue( $GLOBALS['wgBlockDisablesLogin'] );
	}

	/**
	 * @dataProvider everyRouteStateProvider
	 */
	public function testAccountCreationsAreKeptOutOfTheReadableNewUserLog(
		string $codeLogin,
		bool $allowlistAppliesToSso
	): void {
		$this->setMwGlobals( 'wgLogRestrictions', [] );

		$this->registerWithRoutes( $codeLogin, $allowlistAppliesToSso );

		$this->assertSame( 'memberaccess-manage', $GLOBALS['wgLogRestrictions']['newusers'] ?? null );
	}

	/**
	 * @dataProvider everyRouteStateProvider
	 */
	public function testDeactivationsAreKeptOutOfTheReadableBlockLog(
		string $codeLogin,
		bool $allowlistAppliesToSso
	): void {
		$this->setMwGlobals( 'wgLogRestrictions', [] );

		$this->registerWithRoutes( $codeLogin, $allowlistAppliesToSso );

		$this->assertSame( 'memberaccess-manage', $GLOBALS['wgLogRestrictions']['block'] ?? null );
	}

	public function testALogTypeTheWikiRestrictedFurtherIsLeftAlone(): void {
		$this->setMwGlobals( 'wgLogRestrictions', [ 'newusers' => 'suppressionlog' ] );

		$this->registerWithRoutes( 'allowlisted', true );

		$this->assertSame( 'suppressionlog', $GLOBALS['wgLogRestrictions']['newusers'] );
	}

	public function testALogTypeTheWikiDeclaredPublicIsStillClosed(): void {
		$this->setMwGlobals( 'wgLogRestrictions', [ 'newusers' => '*' ] );

		$this->registerWithRoutes( 'allowlisted', true );

		$this->assertSame( 'memberaccess-manage', $GLOBALS['wgLogRestrictions']['newusers'] );
	}

	/**
	 * @dataProvider everyRouteStateProvider
	 */
	public function testAddressesAreAcceptableUsernames(
		string $codeLogin,
		bool $allowlistAppliesToSso
	): void {
		$this->setMwGlobals( 'wgInvalidUsernameCharacters', '@:' );

		$this->registerWithRoutes( $codeLogin, $allowlistAppliesToSso );

		$this->assertSame( ':', $GLOBALS['wgInvalidUsernameCharacters'] );
	}

	/**
	 * @dataProvider everyRouteStateProvider
	 */
	public function testUserRightsCanTargetAnAccountNamedAfterAnAddress(
		string $codeLogin,
		bool $allowlistAppliesToSso
	): void {
		$this->setMwGlobals( 'wgUserrightsInterwikiDelimiter', '@' );

		$this->registerWithRoutes( $codeLogin, $allowlistAppliesToSso );

		$this->assertSame( '@@', $GLOBALS['wgUserrightsInterwikiDelimiter'] );
	}

	public function testUserRightsDelimiterThatCannotClashIsLeftAlone(): void {
		$this->setMwGlobals( 'wgUserrightsInterwikiDelimiter', '#' );

		$this->registerWithRoutes( 'allowlisted', true );

		$this->assertSame( '#', $GLOBALS['wgUserrightsInterwikiDelimiter'] );
	}

	/**
	 * Every member's account is created by their first login, whichever route that is, and both
	 * member routes autocreate through AuthManager, which asks this right of the anonymous visitor
	 * logging in.
	 *
	 * @dataProvider routeThatLogsAMemberInProvider
	 */
	public function testAnonymousVisitorsMayHaveAnAccountCreatedForThem(
		string $codeLogin,
		bool $allowlistAppliesToSso
	): void {
		$this->setMwGlobals( 'wgGroupPermissions', [ '*' => [ 'autocreateaccount' => false ] ] );

		$this->registerWithRoutes( $codeLogin, $allowlistAppliesToSso );

		$this->assertSame( [ '*' => [ 'autocreateaccount' => true ] ], $GLOBALS['wgGroupPermissions'] );
	}

	/**
	 * The one change that widens what an anonymous visitor may do, so no member route means no
	 * widening. Single sign-on outside the allowlist is plain PluggableAuth, and what that needs
	 * stays the wiki's to grant.
	 *
	 * @dataProvider noMemberRouteProvider
	 */
	public function testNoMemberRouteLeavesAnonymousVisitorsWithTheRightsTheWikiGaveThem(
		string $codeLogin,
		bool $allowlistAppliesToSso
	): void {
		$this->setMwGlobals( 'wgGroupPermissions', [ '*' => [ 'autocreateaccount' => false ] ] );

		$this->registerWithRoutes( $codeLogin, $allowlistAppliesToSso );

		$this->assertSame( [ '*' => [ 'autocreateaccount' => false ] ], $GLOBALS['wgGroupPermissions'] );
	}

	/**
	 * @dataProvider offeredCodeRouteProvider
	 */
	public function testACodeRequestIsNeverMetWithAPerAddressCaptcha(
		string $codeLogin,
		bool $allowlistAppliesToSso
	): void {
		$this->setMwGlobals( 'wgCaptchaTriggers', [ 'badlogin' => true, 'badloginperuser' => true ] );

		$this->registerWithRoutes( $codeLogin, $allowlistAppliesToSso );

		$this->assertSame( [ 'badlogin' => true, 'badloginperuser' => false ], $GLOBALS['wgCaptchaTriggers'] );
	}

	/**
	 * Turning the trigger off costs the wiki ConfirmEdit's escalation on every account that does
	 * have a password, which buys nothing where there is no code request to meet a captcha. Single
	 * sign-on issues no code, so it does not pay that price either.
	 *
	 * @dataProvider codeRouteThatIsOffProvider
	 */
	public function testCodeRouteThatIsOffLeavesThePerAddressCaptchaAlone(
		string $codeLogin,
		bool $allowlistAppliesToSso
	): void {
		$this->setMwGlobals( 'wgCaptchaTriggers', [ 'badlogin' => true, 'badloginperuser' => true ] );

		$this->registerWithRoutes( $codeLogin, $allowlistAppliesToSso );

		$this->assertSame( [ 'badlogin' => true, 'badloginperuser' => true ], $GLOBALS['wgCaptchaTriggers'] );
	}

	/**
	 * @dataProvider routeThatLogsAMemberInProvider
	 */
	public function testRememberedLoginsLastTheConfiguredMemberSessionDuration(
		string $codeLogin,
		bool $allowlistAppliesToSso
	): void {
		$this->setMwGlobals( [
			'wgMemberAccessSessionDurationSeconds' => 4321,
			'wgExtendedLoginCookieExpiration' => 12345
		] );

		$this->registerWithRoutes( $codeLogin, $allowlistAppliesToSso );

		$this->assertSame( 4321, $GLOBALS['wgExtendedLoginCookieExpiration'] );
	}

	/**
	 * The duration is there so that members are not asked for a code often, and it decides how long
	 * a remembered login lasts for everyone on the wiki. A wiki no member can log in to keeps the
	 * lifetime it had.
	 *
	 * @dataProvider noMemberRouteProvider
	 */
	public function testCookieExpirationIsLeftAloneWhereNoRouteLogsAMemberIn(
		string $codeLogin,
		bool $allowlistAppliesToSso
	): void {
		$this->setMwGlobals( [
			'wgMemberAccessSessionDurationSeconds' => 4321,
			'wgExtendedLoginCookieExpiration' => 12345
		] );

		$this->registerWithRoutes( $codeLogin, $allowlistAppliesToSso );

		$this->assertSame( 12345, $GLOBALS['wgExtendedLoginCookieExpiration'] );
	}

	/**
	 * The gate is read as MemberAccessExtension reads it, so that both agree on whether the route
	 * makes members: only an explicit true holds single sign-on to the allowlist, which leaves a
	 * wiki that set nothing with no route at all.
	 *
	 * @dataProvider ssoGateThatIsNoExplicitTrueProvider
	 */
	public function testOnlyAnExplicitTrueHoldsSingleSignOnToTheAllowlist( mixed $allowlistAppliesToSso ): void {
		$this->setMwGlobals( [
			'wgMemberAccessSessionDurationSeconds' => 4321,
			'wgExtendedLoginCookieExpiration' => 12345
		] );

		$this->registerWithRoutes( 'off', $allowlistAppliesToSso );

		$this->assertSame( 12345, $GLOBALS['wgExtendedLoginCookieExpiration'] );
	}

	public static function ssoGateThatIsNoExplicitTrueProvider(): iterable {
		yield 'the false it defaults to' => [ false ];
		yield 'a truthy value that is not true' => [ 1 ];
		yield 'a falsy value that is not false' => [ 0 ];
		yield 'a value nobody recognises' => [ 'yes' ];
		yield 'nothing at all' => [ null ];
	}

	public function testSessionDurationOfZeroLeavesTheCookieExpirationAlone(): void {
		$this->setMwGlobals( [
			'wgMemberAccessSessionDurationSeconds' => 0,
			'wgExtendedLoginCookieExpiration' => 12345
		] );

		$this->registerWithRoutes( 'allowlisted', true );

		$this->assertSame( 12345, $GLOBALS['wgExtendedLoginCookieExpiration'] );
	}

	/**
	 * The code route settings that offer the route, against both settings of the allowlist over
	 * single sign-on.
	 */
	public static function offeredCodeRouteProvider(): iterable {
		yield 'allowlisted code route, single sign-on left alone' => [ 'allowlisted', false ];
		yield 'allowlisted code route and the allowlist over single sign-on' => [ 'allowlisted', true ];
		yield 'open code route, single sign-on left alone' => [ 'open', false ];
		yield 'open code route and the allowlist over single sign-on' => [ 'open', true ];
	}

	/**
	 * A code route setting that names no route, which a setting nobody recognises does, and so does
	 * an empty one.
	 */
	public static function codeRouteThatIsOffProvider(): iterable {
		yield 'no code route, single sign-on left alone' => [ 'off', false ];
		yield 'no code route, the allowlist over single sign-on' => [ 'off', true ];
		yield 'a code route setting nobody recognises' => [ 'sometimes', false ];
		yield 'an empty code route setting' => [ '', true ];
	}

	public static function everyRouteStateProvider(): iterable {
		yield from self::offeredCodeRouteProvider();
		yield from self::codeRouteThatIsOffProvider();
	}

	/**
	 * Every state a member can log in from: the code route offered, or the allowlist governing
	 * single sign-on, which is what makes an account that route creates a member.
	 */
	public static function routeThatLogsAMemberInProvider(): iterable {
		yield from self::offeredCodeRouteProvider();
		yield 'no code route, the allowlist over single sign-on' => [ 'off', true ];
	}

	/**
	 * No state here logs a member in: the code route names no route, and single sign-on is left
	 * alone.
	 */
	public static function noMemberRouteProvider(): iterable {
		yield 'no code route, single sign-on left alone' => [ 'off', false ];
		yield 'a code route setting nobody recognises' => [ 'sometimes', false ];
		yield 'an empty code route setting' => [ '', false ];
	}

	protected function setUp(): void {
		parent::setUp();

		$this->setMwGlobals( [
			'wgBlockDisablesLogin' => $GLOBALS['wgBlockDisablesLogin'],
			// ConfirmEdit is optional, and a wiki without it has no triggers until something
			// writes them, which the extension only does while the code route is offered.
			'wgCaptchaTriggers' => $GLOBALS['wgCaptchaTriggers'] ?? [],
			'wgExtendedLoginCookieExpiration' => $GLOBALS['wgExtendedLoginCookieExpiration'],
			'wgGroupPermissions' => $GLOBALS['wgGroupPermissions'],
			'wgInvalidUsernameCharacters' => $GLOBALS['wgInvalidUsernameCharacters'],
			'wgLogRestrictions' => $GLOBALS['wgLogRestrictions'],
			'wgRevokePermissions' => $GLOBALS['wgRevokePermissions'],
			'wgUserrightsInterwikiDelimiter' => $GLOBALS['wgUserrightsInterwikiDelimiter']
		] );
	}

	private function registerWithRoutes( string $codeLogin, mixed $allowlistAppliesToSso ): void {
		$this->setMwGlobals( [
			'wgMemberAccessCodeLogin' => $codeLogin,
			'wgMemberAccessApplyAllowlistToSso' => $allowlistAppliesToSso
		] );

		RegistrationHandler::onRegistration();
	}

	private function reader(): Authority {
		return $this->getMutableTestUser( [ 'reader' ] )->getAuthority();
	}

}
