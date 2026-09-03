<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\Auth\AuthManager;
use MediaWiki\SpecialPage\AuthManagerSpecialPage;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\EnterCodeRequest;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\LoginCodeRequest;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\ResendCodeRequest;
use ProfessionalWiki\MemberAccess\EntryPoints\LoginFormHandler;
use ReflectionMethod;

/**
 * How the login form is laid out is decided from the requests and the described fields alone, so it
 * is asked here without a form to draw.
 *
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\LoginFormHandler
 */
class LoginFormHandlerTest extends MediaWikiIntegrationTestCase {

	private const CORE_LOGIN_BUTTON = 'loginattempt';
	private const CORE_LOGIN_BUTTON_WEIGHT = 100;
	private const CORE_HELP_LINK_WEIGHT = 200;
	private const DEFAULT_SUBMIT_FIELD = 'memberaccessDefaultSubmit';
	private const MOBILE_WATERMARK_FIELD = 'mfLogo';

	public function testCodeScreenOffersAWayBackAndAWayToAnotherCode(): void {
		$descriptor = $this->codeScreen();

		$this->assertArrayHasKey( 'memberaccessResendLine', $descriptor );
		$this->assertArrayHasKey( 'memberaccessRestartLine', $descriptor );
	}

	public function testCodeScreenKeepsTheFieldThePressIsCollectedFrom(): void {
		$descriptor = $this->codeScreen();

		$this->assertArrayHasKey( ResendCodeRequest::BUTTON_NAME, $descriptor );
	}

	/**
	 * Pressing Enter in the code box submits the form's first button, which has to go on being the
	 * one that enters the code rather than the one that replaces it.
	 */
	public function testCollectedFieldStaysBelowTheButtonThatEntersTheCode(): void {
		$descriptor = $this->codeScreen();

		$this->assertGreaterThan(
			self::CORE_LOGIN_BUTTON_WEIGHT,
			$descriptor[ResendCodeRequest::BUTTON_NAME]['weight']
		);
	}

	public function testWithdrawnOfferLeavesNoFieldToPress(): void {
		$descriptor = $this->codeScreen( new ResendCodeRequest( available: false ) );

		$this->assertArrayNotHasKey( ResendCodeRequest::BUTTON_NAME, $descriptor );
		$this->assertArrayHasKey( 'memberaccessResendLine', $descriptor );
	}

	/**
	 * A session begun before the button existed carries no request for it, and the form cannot be
	 * told to describe a field it was never given.
	 */
	public function testScreenWithoutAResendRequestIsLaidOutWithoutOne(): void {
		$descriptor = $this->handle(
			AuthManager::ACTION_LOGIN_CONTINUE,
			[ EnterCodeRequest::CODE_FIELD => [ 'type' => 'text' ] ],
			[ new EnterCodeRequest() ]
		);

		$this->assertArrayNotHasKey( ResendCodeRequest::BUTTON_NAME, $descriptor );
	}

	public function testCodeBoxSaysWhatItTakes(): void {
		$descriptor = $this->codeScreen();

		$this->assertSame( 'one-time-code', $descriptor[EnterCodeRequest::CODE_FIELD]['autocomplete'] );
		$this->assertSame( 6, $descriptor[EnterCodeRequest::CODE_FIELD]['maxlength'] );
	}

	public function testCodeBoxTakesACodeCopiedFromTheMail(): void {
		$this->assertMatchesRegularExpression( $this->codeBoxPattern(), '406323' );
	}

	public function testCodeBoxRefusesLessThanAWholeCode(): void {
		$this->assertDoesNotMatchRegularExpression( $this->codeBoxPattern(), '40632' );
	}

	/**
	 * Anchored at both ends, which is how a browser reads an HTML pattern attribute.
	 */
	private function codeBoxPattern(): string {
		return '/^(?:' . $this->codeScreen()[EnterCodeRequest::CODE_FIELD]['pattern'] . ')$/';
	}

	public function testAddressBoxSaysItTakesAnAddress(): void {
		$this->assertSame( 'email', $this->loginScreen()[LoginCodeRequest::EMAIL_FIELD]['type'] );
	}

	public function testMemberRouteIsLaidOutAboveThePasswordForm(): void {
		$this->assertSame(
			[
				self::DEFAULT_SUBMIT_FIELD,
				LoginCodeRequest::EMAIL_FIELD,
				LoginCodeRequest::BUTTON_NAME,
				'memberaccessDivider',
				'username',
				'password',
				self::CORE_LOGIN_BUTTON,
				'linkcontainer'
			],
			array_keys( $this->laidOutByCore( $this->loginScreen() ) )
		);
	}

	public function testAddressBoxIsFirstInTheTabOrder(): void {
		$this->assertSame( 1, $this->laidOutByCore( $this->loginScreen() )[LoginCodeRequest::EMAIL_FIELD]['tabindex'] );
	}

	public function testAddressBoxIsWhereTheFormOpens(): void {
		$this->assertTrue( $this->loginScreen()[LoginCodeRequest::EMAIL_FIELD]['autofocus'] );
	}

	/**
	 * MobileFrontend heads the mobile login form with a watermark it leaves unweighted, which led
	 * the form until the member section was described below zero.
	 */
	public function testMobileWatermarkGoesOnLeadingTheForm(): void {
		$laidOut = $this->laidOutByCore( $this->handle(
			AuthManager::ACTION_LOGIN,
			[ self::MOBILE_WATERMARK_FIELD => [ 'type' => 'info' ] ] + $this->coreLoginForm(),
			[ new LoginCodeRequest() ]
		) );

		$this->assertSame( self::MOBILE_WATERMARK_FIELD, array_key_first( $laidOut ) );
	}

	public function testCaptchaIsLaidOutBelowBothRoutes(): void {
		$laidOut = $this->laidOutByCore( $this->handle(
			AuthManager::ACTION_LOGIN,
			$this->coreLoginForm() + [ 'captchaInfo' => [ 'type' => 'info' ], 'captchaWord' => [ 'type' => 'text' ] ],
			[ new LoginCodeRequest() ]
		) );

		$this->assertSame(
			[ self::CORE_LOGIN_BUTTON, 'captchaInfo', 'captchaWord', 'linkcontainer' ],
			array_slice( array_keys( $laidOut ), -4 )
		);
	}

	public function testMemberRouteButtonIsTheFormsPrimaryButton(): void {
		$this->assertSame(
			[ 'primary', 'progressive' ],
			$this->loginScreen()[LoginCodeRequest::BUTTON_NAME]['flags']
		);
	}

	public function testPasswordLoginButtonIsNoLongerPrimary(): void {
		$this->assertSame( [ 'progressive' ], $this->loginScreen()[self::CORE_LOGIN_BUTTON]['flags'] );
	}

	/**
	 * A wiki that offers no password login has single sign-on buttons under the member route
	 * instead, which a divider naming the password form would name wrongly.
	 */
	public function testNoDividerIsDescribedWhereNoPasswordFormIs(): void {
		$this->assertArrayNotHasKey( 'memberaccessDivider', $this->loginScreenWithoutAPasswordForm() );
	}

	/**
	 * Core describes its button before this hook runs; a form that did not would be handed a field
	 * with nothing in it, which it cannot draw.
	 */
	public function testPasswordLoginButtonIsNotInventedWhereTheFormDescribedNone(): void {
		$descriptor = $this->handle(
			AuthManager::ACTION_LOGIN,
			[ LoginCodeRequest::EMAIL_FIELD => [ 'type' => 'text' ], LoginCodeRequest::BUTTON_NAME => [ 'type' => 'submit' ] ],
			[ new LoginCodeRequest() ]
		);

		$this->assertArrayNotHasKey( self::CORE_LOGIN_BUTTON, $descriptor );
	}

	/**
	 * Enter submits through the form's first submit control, so what that one is named decides the
	 * route for the visitor. Named after neither, it leaves the decision with the boxes.
	 */
	public function testDefaultButtonNamesNeitherRoute(): void {
		$button = $this->loginScreen()[self::DEFAULT_SUBMIT_FIELD]['default'];

		$this->assertStringContainsString( 'type="submit"', $button );
		$this->assertStringNotContainsString( 'name=', $button );
	}

	public function testDefaultButtonIsReachedByNeitherTabNorScreenReader(): void {
		$button = $this->loginScreen()[self::DEFAULT_SUBMIT_FIELD]['default'];

		$this->assertStringContainsString( 'tabindex="-1"', $button );
		$this->assertStringContainsString( 'aria-hidden="true"', $button );
	}

	public function testDefaultButtonIsDrawnAsMarkupRatherThanAsEscapedText(): void {
		$this->assertTrue( $this->loginScreen()[self::DEFAULT_SUBMIT_FIELD]['raw'] );
	}

	public function testDefaultButtonIsLeftOutOfTheTabOrder(): void {
		$this->assertArrayNotHasKey( 'tabindex', $this->laidOutByCore( $this->loginScreen() )[self::DEFAULT_SUBMIT_FIELD] );
	}

	/**
	 * The stylesheet is all that hides the field, so the class it carries has to be one the
	 * stylesheet knows.
	 */
	public function testDefaultButtonIsHiddenByTheStylesheet(): void {
		$this->assertStringContainsString(
			'.' . $this->loginScreen()[self::DEFAULT_SUBMIT_FIELD]['cssclass'] . ' {',
			file_get_contents( __DIR__ . '/../../../modules/ext.memberAccess.loginForm.less' )
		);
	}

	public function testFormsWithoutOurFieldsAreLeftAlone(): void {
		$form = [
			'username' => [ 'type' => 'text' ],
			self::CORE_LOGIN_BUTTON => [ 'type' => 'submit', 'weight' => self::CORE_LOGIN_BUTTON_WEIGHT ]
		];

		$this->assertSame( $form, $this->handle( AuthManager::ACTION_LOGIN, $form, [] ) );
	}

	/**
	 * The login form as core hands it to this hook: its own fields first, the boxes unweighted and
	 * the log in button and help link weighted as core weights them, then the member route's.
	 * {@see \MediaWiki\SpecialPage\LoginSignupSpecialPage::getFieldDefinitions}
	 *
	 * @return array<string, mixed>
	 */
	private function coreLoginForm(): array {
		return [
			'username' => [ 'type' => 'text' ],
			'password' => [ 'type' => 'password' ],
			self::CORE_LOGIN_BUTTON => [ 'type' => 'submit', 'weight' => self::CORE_LOGIN_BUTTON_WEIGHT ],
			'linkcontainer' => [ 'type' => 'info', 'weight' => self::CORE_HELP_LINK_WEIGHT ],
			LoginCodeRequest::EMAIL_FIELD => [ 'type' => 'text' ],
			LoginCodeRequest::BUTTON_NAME => [ 'type' => 'submit' ]
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function loginScreen(): array {
		return $this->handle( AuthManager::ACTION_LOGIN, $this->coreLoginForm(), [ new LoginCodeRequest() ] );
	}

	/**
	 * A wiki whose other route is single sign-on: no password login is described.
	 *
	 * @return array<string, mixed>
	 */
	private function loginScreenWithoutAPasswordForm(): array {
		$form = $this->coreLoginForm();
		unset( $form['username'], $form['password'] );

		return $this->handle( AuthManager::ACTION_LOGIN, $form, [ new LoginCodeRequest() ] );
	}

	/**
	 * What core makes of the described fields once every handler has had its say: laid out by
	 * weight, an absent one counting as zero, with the tab order numbered from what that leaves.
	 *
	 * @param array<string, mixed> $descriptor
	 * @return array<string, mixed>
	 */
	private function laidOutByCore( array $descriptor ): array {
		$loginPage = $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'Userlogin' );

		( new ReflectionMethod( AuthManagerSpecialPage::class, 'sortFormDescriptorFields' ) )
			->invokeArgs( null, [ &$descriptor ] );
		( new ReflectionMethod( AuthManagerSpecialPage::class, 'addTabIndex' ) )
			->invokeArgs( $loginPage, [ &$descriptor ] );

		return $descriptor;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function codeScreen( ?ResendCodeRequest $resend = null ): array {
		return $this->handle(
			AuthManager::ACTION_LOGIN_CONTINUE,
			[
				EnterCodeRequest::CODE_FIELD => [ 'type' => 'text' ],
				ResendCodeRequest::BUTTON_NAME => [ 'type' => 'submit' ]
			],
			[ new EnterCodeRequest(), $resend ?? new ResendCodeRequest() ]
		);
	}

	/**
	 * @param array<string, mixed> $descriptor
	 * @param \MediaWiki\Auth\AuthenticationRequest[] $requests
	 * @return array<string, mixed>
	 */
	private function handle( string $action, array $descriptor, array $requests ): array {
		( new LoginFormHandler() )->onAuthChangeFormFields( $requests, [], $descriptor, $action );

		return $descriptor;
	}

}
