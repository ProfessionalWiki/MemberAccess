<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\EnterCodeRequest;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\ResendCodeRequest;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\FixedSecretGenerator;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SecretSequenceGenerator;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyEmailer;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * What the screen asking for the code offers: the address the code went to, so that a mistyped one
 * can be seen, and a way to ask for another code.
 *
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\MemberAuthenticationProvider
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\ResendCodeRequest
 */
class CodeScreenTest extends MediaWikiIntegrationTestCase {

	use AuthenticationProviderRegistration;
	use CodeRequestSubmission;

	private const CODE = '12345678';
	private const ADDRESS = 'jane@example.com';
	private const RETURN_TO_URL = 'https://wiki.example.com/return';

	private SpyEmailer $emailer;

	protected function setUp(): void {
		parent::setUp();

		$this->emailer = new SpyEmailer();
		$this->setService( 'Emailer', $this->emailer );
		$this->registerOurAuthenticationProvider();
		$this->overrideConfigValue( 'MemberAccessCodeLogin', 'allowlisted' );

		MemberAccessExtension::getInstance()->setStashOverride( new HashBagOStuff() );
		MemberAccessExtension::getInstance()->setSecretGeneratorOverride( new FixedSecretGenerator( self::CODE ) );

		$this->admit( self::ADDRESS );
	}

	protected function tearDown(): void {
		MemberAccessExtension::getInstance()->setStashOverride( null );
		MemberAccessExtension::getInstance()->setSecretGeneratorOverride( null );

		parent::tearDown();
	}

	public function testCodeScreenNamesTheAddressTheCodeWentTo(): void {
		$response = $this->requestCode( self::ADDRESS );

		$this->assertSame( AuthenticationResponse::UI, $response->status );
		$this->assertNamesTheAddress( $response, self::ADDRESS );
	}

	/**
	 * An address no entry admits is named back just the same, since saying nothing about it is what
	 * keeps the screen from telling one address from another.
	 */
	public function testUnadmittedAddressIsNamedBackJustTheSame(): void {
		$response = $this->requestCode( 'stranger@example.net' );

		$this->assertSame( AuthenticationResponse::UI, $response->status );
		$this->assertNamesTheAddress( $response, 'stranger@example.net' );
	}

	/**
	 * The screen the address is named on is parsed as wikitext, and the address has been through
	 * nothing but a trim. Named as an ordinary parameter it would be substituted before that parse,
	 * and an address holding a transclusion would pull the page it names into the login screen for
	 * anyone to read.
	 */
	public function testAddressHoldingWikitextIsNamedBackAsItWasTyped(): void {
		$this->requestCode( '{{:Main_Page}}@example.org' );

		$rendered = $this->requestCode( '{{:Main_Page}}@example.org' )->message?->parse();

		$this->assertStringContainsString( '{{:Main_Page}}@example.org', (string)$rendered );
	}

	public function testAddressIsStillNamedAfterAWrongCode(): void {
		$this->requestCode( self::ADDRESS );

		$response = $this->enterCode( '00000000' );

		$this->assertSame( AuthenticationResponse::UI, $response->status );
		$this->assertNamesTheAddress( $response, self::ADDRESS );
	}

	public function testResendingMailsAnotherCodeToTheSameAddress(): void {
		$this->requestCode( self::ADDRESS );

		$this->resendCode();

		$this->assertSame(
			[ self::ADDRESS, self::ADDRESS ],
			array_column( $this->emailer->getSentMails(), 'to' )
		);
	}

	public function testResendingKeepsTheVisitorOnTheCodeScreen(): void {
		$this->requestCode( self::ADDRESS );

		$response = $this->resendCode();

		$this->assertSame( AuthenticationResponse::UI, $response->status );
		$this->assertNotNull(
			AuthenticationRequest::getRequestByClass( $response->neededRequests, EnterCodeRequest::class )
		);
	}

	/**
	 * The code that was sent second is the one that works, or asking for another would leave two
	 * codes open on one address.
	 */
	public function testCodeSentBeforeAResendNoLongerWorks(): void {
		MemberAccessExtension::getInstance()->setSecretGeneratorOverride(
			new SecretSequenceGenerator( [ self::CODE, '87654321' ] )
		);
		$this->requestCode( self::ADDRESS );
		$this->resendCode();

		$this->assertSame( AuthenticationResponse::UI, $this->enterCode( self::CODE )->status );
		$this->assertSame( AuthenticationResponse::PASS, $this->enterCode( '87654321' )->status );
	}

	/**
	 * Required, the box would have to be filled before the resend button could be pressed, which is
	 * the one thing a visitor without a working code needs to do.
	 */
	public function testCodeBoxIsOptionalSoThatResendingCanBePressedWithoutIt(): void {
		$fieldInfo = ( new EnterCodeRequest() )->getFieldInfo();

		$this->assertTrue( $fieldInfo[EnterCodeRequest::CODE_FIELD]['optional'] ?? false );
	}

	/**
	 * An empty box is no answer to spend an attempt on. Submitted more times than the code has
	 * attempts, it would otherwise burn the code the visitor is still waiting to use.
	 */
	public function testEmptyCodeIsAnsweredWithoutSpendingAnAttempt(): void {
		$this->overrideConfigValue( 'MemberAccessCodeAttemptLimit', 2 );
		$this->requestCode( self::ADDRESS );

		$this->enterCode( '' );
		$this->enterCode( '' );
		$this->enterCode( '' );

		$this->assertSame( AuthenticationResponse::PASS, $this->enterCode( self::CODE )->status );
	}

	/**
	 * The code already sent may still be usable, so a resend the throttle refuses leaves the
	 * visitor on the screen holding it rather than back at the address box.
	 */
	public function testThrottledResendKeepsTheVisitorOnTheCodeScreen(): void {
		$this->overrideConfigValue( 'MemberAccessEmailBurstLimit', 1 );
		$this->requestCode( self::ADDRESS );

		$response = $this->resendCode();

		$this->assertSame( AuthenticationResponse::UI, $response->status );
		$this->assertNotNull(
			AuthenticationRequest::getRequestByClass( $response->neededRequests, EnterCodeRequest::class )
		);
	}

	public function testThrottledResendWithdrawsTheOfferOfAnother(): void {
		$this->overrideConfigValue( 'MemberAccessEmailBurstLimit', 1 );
		$this->requestCode( self::ADDRESS );

		$response = $this->resendCode();

		$this->assertFalse( $this->offeredResend( $response )?->isAvailable() );
	}

	public function testWithdrawnOfferStaysWithdrawnOnTheNextScreen(): void {
		$this->overrideConfigValue( 'MemberAccessEmailBurstLimit', 1 );
		$this->requestCode( self::ADDRESS );
		$this->resendCode();

		$response = $this->enterCode( '00000000' );

		$this->assertFalse( $this->offeredResend( $response )?->isAvailable() );
	}

	/**
	 * The allowance frees again on its own, and a code sent after it is proof the throttle is no
	 * longer refusing, so the screen stops saying that it is.
	 */
	public function testCodeSentAfterARefusalOffersAnotherAgain(): void {
		$this->overrideConfigValue( 'MemberAccessEmailBurstLimit', 1 );
		$this->requestCode( self::ADDRESS );
		$this->resendCode();

		$this->overrideConfigValue( 'MemberAccessEmailBurstLimit', 10 );
		$response = $this->resendCode();

		$this->assertTrue( $this->offeredResend( $response )?->isAvailable() );
	}

	public function testCodeStillWorksAfterAThrottledResend(): void {
		$this->overrideConfigValue( 'MemberAccessEmailBurstLimit', 1 );
		$this->requestCode( self::ADDRESS );
		$this->resendCode();

		$this->assertSame( AuthenticationResponse::PASS, $this->enterCode( self::CODE )->status );
	}

	public function testResendingWithoutACodeRequestInTheSessionIsRefused(): void {
		$this->requestCode( self::ADDRESS );
		$this->getServiceContainer()->getAuthManager()
			->removeAuthenticationSessionData( 'MemberAccessCodeAddress' );

		$response = $this->resendCode();

		$this->assertSame( AuthenticationResponse::FAIL, $response->status );
		$this->assertSame( 'memberaccess-auth-failed', $response->message?->getKey() );
	}

	/**
	 * Asserted on what the screen renders rather than on what the message was handed, since between
	 * the two is the parse that an address is otherwise free to carry wikitext through.
	 */
	private function assertNamesTheAddress( AuthenticationResponse $response, string $address ): void {
		$this->assertStringContainsString( $address, (string)$response->message?->parse() );
	}

	private function offeredResend( AuthenticationResponse $response ): ?ResendCodeRequest {
		$request = AuthenticationRequest::getRequestByClass(
			$response->neededRequests,
			ResendCodeRequest::class
		);

		return $request instanceof ResendCodeRequest ? $request : null;
	}

	private function admit( string $address ): void {
		$extension = MemberAccessExtension::getInstance();
		$group = $extension->newMemberGroupRepository()->createGroup( 'Testers' );

		$extension->newAllowlistRepository()->addEntry(
			$group->id,
			AllowlistValue::fromString( $address ),
			0
		);
	}

	private function requestCode( string $email ): AuthenticationResponse {
		return $this->getServiceContainer()->getAuthManager()
			->beginAuthentication( $this->submittedCodeRequest( $email ), self::RETURN_TO_URL );
	}

	private function enterCode( string $code ): AuthenticationResponse {
		$request = new EnterCodeRequest();
		$request->memberaccessCode = $code;

		return $this->getServiceContainer()->getAuthManager()->continueAuthentication( [ $request ] );
	}

	private function resendCode(): AuthenticationResponse {
		$request = new ResendCodeRequest();
		$request->memberaccessResend = true;

		return $this->getServiceContainer()->getAuthManager()->continueAuthentication( [ $request ] );
	}

}
