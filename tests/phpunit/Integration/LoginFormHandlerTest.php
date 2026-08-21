<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\Auth\AuthManager;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\EnterCodeRequest;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\LoginCodeRequest;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\ResendCodeRequest;
use ProfessionalWiki\MemberAccess\EntryPoints\LoginFormHandler;

/**
 * How the login form is laid out is decided from the requests and the described fields alone, so it
 * is asked here without a form to draw.
 *
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\LoginFormHandler
 */
class LoginFormHandlerTest extends MediaWikiIntegrationTestCase {

	private const CORE_LOGIN_BUTTON_WEIGHT = 100;

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
		$this->assertSame( 8, $descriptor[EnterCodeRequest::CODE_FIELD]['maxlength'] );
	}

	public function testLoginFormSetsTheMemberRouteApart(): void {
		$descriptor = $this->handle(
			AuthManager::ACTION_LOGIN,
			[ LoginCodeRequest::EMAIL_FIELD => [ 'type' => 'text' ], LoginCodeRequest::BUTTON_NAME => [ 'type' => 'submit' ] ],
			[ new LoginCodeRequest() ]
		);

		$this->assertArrayHasKey( 'memberaccessDivider', $descriptor );
		$this->assertSame( 'email', $descriptor[LoginCodeRequest::EMAIL_FIELD]['type'] );
	}

	/**
	 * The log in button above it is the form's one primary button.
	 */
	public function testMemberRouteButtonIsProgressiveWithoutBeingPrimary(): void {
		$descriptor = $this->handle(
			AuthManager::ACTION_LOGIN,
			[ LoginCodeRequest::EMAIL_FIELD => [ 'type' => 'text' ], LoginCodeRequest::BUTTON_NAME => [ 'type' => 'submit' ] ],
			[ new LoginCodeRequest() ]
		);

		$this->assertSame( [ 'progressive' ], $descriptor[LoginCodeRequest::BUTTON_NAME]['flags'] );
	}

	public function testFormsWithoutOurFieldsAreLeftAlone(): void {
		$descriptor = $this->handle( AuthManager::ACTION_LOGIN, [ 'username' => [ 'type' => 'text' ] ], [] );

		$this->assertSame( [ 'username' => [ 'type' => 'text' ] ], $descriptor );
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
