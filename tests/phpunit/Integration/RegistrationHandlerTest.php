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

	public function testBlockingAMemberKeepsThemOutOfAPrivateWiki(): void {
		$this->assertTrue( $GLOBALS['wgBlockDisablesLogin'] );
	}

	public function testAnonymousVisitorsMayHaveAnAccountCreatedForThem(): void {
		$this->assertTrue( $GLOBALS['wgGroupPermissions']['*']['autocreateaccount'] );
	}

	/**
	 * @dataProvider offeredCodeRouteProvider
	 */
	public function testACodeRequestIsNeverMetWithAPerAddressCaptcha( string $codeLogin ): void {
		$this->setMwGlobals( [
			'wgCaptchaTriggers' => [ 'badlogin' => true, 'badloginperuser' => true ],
			'wgMemberAccessCodeLogin' => $codeLogin
		] );

		RegistrationHandler::onRegistration();

		$this->assertSame( [ 'badlogin' => true, 'badloginperuser' => false ], $GLOBALS['wgCaptchaTriggers'] );
	}

	public static function offeredCodeRouteProvider(): iterable {
		yield 'allowlisted' => [ 'allowlisted' ];
		yield 'open' => [ 'open' ];
		yield 'a value nobody recognises' => [ 'sometimes' ];
		yield 'an empty setting' => [ '' ];
	}

	/**
	 * Turning the trigger off costs the wiki ConfirmEdit's escalation on every account that does
	 * have a password, which buys nothing where there is no code request to meet a captcha.
	 */
	public function testCodeRouteThatIsOffLeavesThePerAddressCaptchaAlone(): void {
		$this->setMwGlobals( [
			'wgCaptchaTriggers' => [ 'badlogin' => true, 'badloginperuser' => true ],
			'wgMemberAccessCodeLogin' => 'off'
		] );

		RegistrationHandler::onRegistration();

		$this->assertSame( [ 'badlogin' => true, 'badloginperuser' => true ], $GLOBALS['wgCaptchaTriggers'] );
	}

	public function testAddressesAreAcceptableUsernames(): void {
		$this->assertStringNotContainsString( '@', $GLOBALS['wgInvalidUsernameCharacters'] );
	}

	public function testUserRightsCanTargetAnAccountNamedAfterAnAddress(): void {
		$this->setMwGlobals( 'wgUserrightsInterwikiDelimiter', '@' );

		RegistrationHandler::onRegistration();

		$this->assertSame( '@@', $GLOBALS['wgUserrightsInterwikiDelimiter'] );
	}

	public function testUserRightsDelimiterThatCannotClashIsLeftAlone(): void {
		$this->setMwGlobals( 'wgUserrightsInterwikiDelimiter', '#' );

		RegistrationHandler::onRegistration();

		$this->assertSame( '#', $GLOBALS['wgUserrightsInterwikiDelimiter'] );
	}

	public function testRememberedLoginsLastTheConfiguredMemberSessionDuration(): void {
		$this->assertSame(
			$GLOBALS['wgMemberAccessSessionDurationSeconds'],
			$GLOBALS['wgExtendedLoginCookieExpiration']
		);
	}

	public function testRenamingTheReaderGroupMovesTheRevokedRightsWithIt(): void {
		$this->setMwGlobals( [
			'wgMemberAccessReaderGroup' => 'members',
			'wgRevokePermissions' => [ 'reader' => [ 'edit' => true ] ]
		] );

		RegistrationHandler::onRegistration();

		$this->assertSame( [ 'members' => [ 'edit' => true ] ], $GLOBALS['wgRevokePermissions'] );
	}

	public function testAccountCreationsAreKeptOutOfTheReadableNewUserLog(): void {
		$this->assertSame( 'memberaccess-manage', $GLOBALS['wgLogRestrictions']['newusers'] ?? null );
	}

	public function testDeactivationsAreKeptOutOfTheReadableBlockLog(): void {
		$this->assertSame( 'memberaccess-manage', $GLOBALS['wgLogRestrictions']['block'] ?? null );
	}

	public function testALogTypeTheWikiRestrictedFurtherIsLeftAlone(): void {
		$this->setMwGlobals( 'wgLogRestrictions', [ 'newusers' => 'suppressionlog' ] );

		RegistrationHandler::onRegistration();

		$this->assertSame( 'suppressionlog', $GLOBALS['wgLogRestrictions']['newusers'] );
	}

	public function testALogTypeTheWikiDeclaredPublicIsStillClosed(): void {
		$this->setMwGlobals( 'wgLogRestrictions', [ 'newusers' => '*' ] );

		RegistrationHandler::onRegistration();

		$this->assertSame( 'memberaccess-manage', $GLOBALS['wgLogRestrictions']['newusers'] );
	}

	public function testSessionDurationOfZeroLeavesTheCookieExpirationAlone(): void {
		$this->setMwGlobals( [
			'wgMemberAccessSessionDurationSeconds' => 0,
			'wgExtendedLoginCookieExpiration' => 12345
		] );

		RegistrationHandler::onRegistration();

		$this->assertSame( 12345, $GLOBALS['wgExtendedLoginCookieExpiration'] );
	}

	protected function setUp(): void {
		parent::setUp();

		$this->setMwGlobals( [
			'wgBlockDisablesLogin' => $GLOBALS['wgBlockDisablesLogin'],
			'wgCaptchaTriggers' => $GLOBALS['wgCaptchaTriggers'],
			'wgGroupPermissions' => $GLOBALS['wgGroupPermissions'],
			'wgInvalidUsernameCharacters' => $GLOBALS['wgInvalidUsernameCharacters'],
			'wgLogRestrictions' => $GLOBALS['wgLogRestrictions'],
			'wgUserrightsInterwikiDelimiter' => $GLOBALS['wgUserrightsInterwikiDelimiter']
		] );
	}

	private function reader(): Authority {
		return $this->getMutableTestUser( [ 'reader' ] )->getAuthority();
	}

}
