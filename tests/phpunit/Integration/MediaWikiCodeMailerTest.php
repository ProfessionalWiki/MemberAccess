<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MailAddress;
use MediaWiki\Html\TemplateParser;
use MediaWiki\MainConfigNames;
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
	private const string CODE = '123456';

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

	public function testEachPartCarriesTheCode(): void {
		$emailer = $this->sendCode();

		$this->assertStringContainsString( self::CODE, $emailer->getSentMails()[0]['bodyText'] );
		$this->assertStringContainsString( self::CODE, $this->htmlPart( $emailer ) );
	}

	/**
	 * A client that shows only the plain part shows the same code and the same warning, rather than
	 * a note that the mail is better read elsewhere.
	 */
	public function testAFormattedPartIsSentAlongsideThePlainOne(): void {
		$emailer = $this->sendCode();

		$this->assertStringContainsString( '<table', $this->htmlPart( $emailer ) );
		$this->assertStringNotContainsString( '<table', $emailer->getSentMails()[0]['bodyText'] );
	}

	/**
	 * A wiki that has not turned formatted mail on sends this part alone, so what someone who did
	 * not ask for a code should do has to be in it.
	 */
	public function testThePlainPartWarnsWhoeverDidNotAskForACode(): void {
		$emailer = $this->sendCode();

		$this->assertStringContainsString(
			wfMessage( 'memberaccess-code-email-disclaimer' )->inContentLanguage()->text(),
			$emailer->getSentMails()[0]['bodyText']
		);
	}

	public function testEachPartNamesTheWiki(): void {
		$this->overrideConfigValue( MainConfigNames::Sitename, 'Acme Handbook' );

		$emailer = $this->sendCode();

		$this->assertStringContainsString( 'Acme Handbook', $emailer->getSentMails()[0]['bodyText'] );
		$this->assertStringContainsString( 'Acme Handbook', $this->htmlPart( $emailer ) );
	}

	/**
	 * Nothing is fetched when the mail is opened: no remote image for a client to block, and no
	 * link to train its reader into clicking one in a mail about logging in.
	 */
	public function testTheMailAsksForNothingFromTheNetwork(): void {
		$html = $this->htmlPart( $this->sendCode() );

		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'http', $html );
	}

	/**
	 * The site name is a wiki's to set, so it reaches the formatted part as text and not as markup.
	 */
	public function testMarkupInTheSiteNameIsNotMarkupInTheMail(): void {
		$this->overrideConfigValue( MainConfigNames::Sitename, '<script>alert(1)</script>' );

		$html = $this->htmlPart( $this->sendCode() );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * A notification or an inbox listing shows the subject and little else.
	 */
	public function testSubjectLeadsWithTheCode(): void {
		$subject = $this->sendCode()->getSentMails()[0]['subject'];

		$this->assertStringStartsWith( self::CODE, $subject );
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

	public function testEachPartIsTranslated(): void {
		$emailer = $this->sendCode();

		$this->assertStringNotContainsString( 'memberaccess-', $emailer->getSentMails()[0]['bodyText'] );
		$this->assertStringNotContainsString( 'memberaccess-', $this->htmlPart( $emailer ) );
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
			templates: new TemplateParser( __DIR__ . '/../../../templates' ),
			contentLanguage: $this->getServiceContainer()->getContentLanguage(),
			siteName: $this->getConfVar( MainConfigNames::Sitename ),
			logger: $this->logger
		) )->sendCode( $email, self::CODE, $expiryInMinutes );

		return $emailer;
	}

	/**
	 * Asserted on rather than coerced, since every claim made about the formatted part below is a
	 * negative one, and a part that was never sent would satisfy all of them.
	 */
	private function htmlPart( SpyEmailer $emailer ): string {
		$html = $emailer->getSentMails()[0]['bodyHtml'] ?? null;

		$this->assertIsString( $html, 'no formatted part was sent' );
		$this->assertNotSame( '', $html );

		return $html;
	}

}
