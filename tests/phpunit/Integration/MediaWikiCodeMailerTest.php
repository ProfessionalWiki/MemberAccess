<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MailAddress;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Persistence\MediaWikiCodeMailer;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyEmailer;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;

/**
 * @covers \ProfessionalWiki\MemberAccess\Persistence\MediaWikiCodeMailer
 */
class MediaWikiCodeMailerTest extends MediaWikiIntegrationTestCase {

	private const string SENDER = 'no-reply@example.com';
	private const string RECIPIENT = 'jane@example.com';
	private const string CODE = '12345678';

	private SpyLogger $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = new SpyLogger();
	}

	public function testCodeGoesToTheMember(): void {
		$emailer = $this->sendCode();

		$this->assertSame( self::RECIPIENT, $emailer->getSentMails()[0]['to'] );
	}

	public function testMailComesFromTheConfiguredSender(): void {
		$emailer = $this->sendCode();

		$this->assertSame( self::SENDER, $emailer->getSentMails()[0]['from'] );
	}

	public function testBodyCarriesTheCode(): void {
		$emailer = $this->sendCode();

		$this->assertStringContainsString( self::CODE, $emailer->getSentMails()[0]['bodyText'] );
	}

	public function testBodyCarriesHowLongTheCodeLasts(): void {
		$emailer = $this->sendCode( expiryInMinutes: 17 );

		$this->assertStringContainsString( '17', $emailer->getSentMails()[0]['bodyText'] );
	}

	public function testSubjectIsTranslated(): void {
		$subject = $this->sendCode()->getSentMails()[0]['subject'];

		$this->assertNotSame( '', $subject );
		$this->assertStringNotContainsString( 'memberaccess-', $subject );
	}

	public function testBodyIsTranslated(): void {
		$this->assertStringNotContainsString(
			'memberaccess-',
			$this->sendCode()->getSentMails()[0]['bodyText']
		);
	}

	public function testNothingIsLoggedWhenTheMailGoesOut(): void {
		$this->sendCode();

		$this->assertSame( [], $this->logger->getEntries() );
	}

	public function testFailingToSendIsLogged(): void {
		$this->sendCode( sendSucceeds: false );

		$this->assertNotSame( '', $this->logger->getLog() );
	}

	public function testLogOfAFailedSendDoesNotContainTheAddressOrTheCode(): void {
		$this->sendCode( sendSucceeds: false );

		$this->assertStringNotContainsString( self::RECIPIENT, $this->logger->getLog() );
		$this->assertStringNotContainsString( self::CODE, $this->logger->getLog() );
	}

	private function sendCode( int $expiryInMinutes = 10, bool $sendSucceeds = true ): SpyEmailer {
		$emailer = new SpyEmailer( sendSucceeds: $sendSucceeds );
		$email = NormalizedEmail::fromString( self::RECIPIENT );

		$this->assertNotNull( $email );

		( new MediaWikiCodeMailer(
			emailer: $emailer,
			sender: new MailAddress( self::SENDER ),
			logger: $this->logger
		) )->sendCode( $email, self::CODE, $expiryInMinutes );

		return $emailer;
	}

}
