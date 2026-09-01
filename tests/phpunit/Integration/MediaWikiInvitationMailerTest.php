<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MailAddress;
use MediaWiki\Html\TemplateParser;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Persistence\MediaWikiInvitationMailer;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyEmailer;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;

/**
 * @covers \ProfessionalWiki\MemberAccess\Persistence\MediaWikiInvitationMailer
 */
class MediaWikiInvitationMailerTest extends MediaWikiIntegrationTestCase {

	private const string SENDER = 'no-reply@example.com';
	private const string RECIPIENT = 'jane@example.com';
	private const string LOGIN_URL = 'https://wiki.example.com/index.php/Special:UserLogin';
	private const string MARKUP_RECIPIENT = 'jane{{sitename}}<b>@example.com';

	private SpyLogger $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = new SpyLogger();
	}

	public function testInvitationGoesToTheAdmittedAddress(): void {
		$emailer = $this->sendInvitation();

		$this->assertSame( self::RECIPIENT, $emailer->getSentMails()[0]['to'] );
	}

	public function testMailComesFromTheConfiguredSender(): void {
		$emailer = $this->sendInvitation();

		$this->assertSame( self::SENDER, $emailer->getSentMails()[0]['from'] );
	}

	/**
	 * The plain part sets the URL off on a line of its own, which is what tells it apart from the
	 * address named in the sentence below it.
	 */
	public function testEachPartCarriesTheLoginPage(): void {
		$emailer = $this->sendInvitation();

		$this->assertStringContainsString(
			"\n    " . self::LOGIN_URL . "\n",
			$emailer->getSentMails()[0]['bodyText']
		);
		$this->assertStringContainsString( self::LOGIN_URL, $this->htmlPart( $emailer ) );
	}

	/**
	 * Members log in with the address that was admitted, so the mail names which one that is.
	 */
	public function testEachPartNamesTheAddressToLogInWith(): void {
		$emailer = $this->sendInvitation();

		$this->assertStringContainsString( self::RECIPIENT, $emailer->getSentMails()[0]['bodyText'] );
		$this->assertStringContainsString( self::RECIPIENT, $this->htmlPart( $emailer ) );
	}

	public function testEachPartNamesTheWiki(): void {
		$this->overrideConfigValue( MainConfigNames::Sitename, 'Acme Handbook' );

		$emailer = $this->sendInvitation();

		$this->assertStringContainsString( 'Acme Handbook', $emailer->getSentMails()[0]['bodyText'] );
		$this->assertStringContainsString( 'Acme Handbook', $this->htmlPart( $emailer ) );
	}

	/**
	 * The one link the mail carries says where it goes, character for character, so that a reader
	 * weighing whether to trust it is not asked to take the label's word for the target.
	 */
	public function testTheLinkShowsTheAddressItGoesTo(): void {
		$html = $this->htmlPart( $this->sendInvitation() );

		$this->assertStringContainsString( 'href="' . self::LOGIN_URL . '"', $html );
		$this->assertStringContainsString( '>' . self::LOGIN_URL . '</a>', $html );
	}

	/**
	 * Nothing is fetched when the mail is opened: no remote image for a client to block.
	 */
	public function testTheMailAsksForNoImages(): void {
		$this->assertStringNotContainsString( '<img', $this->htmlPart( $this->sendInvitation() ) );
	}

	/**
	 * A wiki that has not turned formatted mail on sends the plain part alone, so what someone who
	 * was not expecting an invitation should do has to be in it.
	 */
	public function testThePlainPartTellsWhoeverWasNotExpectingIt(): void {
		$emailer = $this->sendInvitation();

		$this->assertStringContainsString(
			wfMessage( 'memberaccess-invitation-email-disclaimer' )->inContentLanguage()->text(),
			$emailer->getSentMails()[0]['bodyText']
		);
	}

	public function testAFormattedPartIsSentAlongsideThePlainOne(): void {
		$emailer = $this->sendInvitation();

		$this->assertStringContainsString( '<table', $this->htmlPart( $emailer ) );
		$this->assertStringNotContainsString( '<table', $emailer->getSentMails()[0]['bodyText'] );
	}

	/**
	 * The site name is a wiki's to set, so it reaches the formatted part as text and not as markup.
	 */
	public function testMarkupInTheSiteNameIsNotMarkupInTheMail(): void {
		$this->overrideConfigValue( MainConfigNames::Sitename, '<script>alert(1)</script>' );

		$html = $this->htmlPart( $this->sendInvitation() );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * An address is whatever an administrator put on the allowlist, so the plain part carries it as
	 * it stands rather than handing it to the message parser to read.
	 */
	public function testWikitextInTheAddressIsNotExpanded(): void {
		$emailer = $this->sendInvitationTo( self::MARKUP_RECIPIENT );

		$this->assertStringContainsString( self::MARKUP_RECIPIENT, $emailer->getSentMails()[0]['bodyText'] );
	}

	/**
	 * And the formatted part carries it as text and not as markup.
	 */
	public function testMarkupInTheAddressIsNotMarkupInTheMail(): void {
		$html = $this->htmlPart( $this->sendInvitationTo( self::MARKUP_RECIPIENT ) );

		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( 'jane{{sitename}}&lt;b&gt;@example.com', $html );
	}

	public function testSubjectIsTranslated(): void {
		$subject = $this->sendInvitation()->getSentMails()[0]['subject'];

		$this->assertNotSame( '', $subject );
		$this->assertStringNotContainsString( 'memberaccess-', $subject );
	}

	public function testEachPartIsTranslated(): void {
		$emailer = $this->sendInvitation();

		$this->assertStringNotContainsString( 'memberaccess-', $emailer->getSentMails()[0]['bodyText'] );
		$this->assertStringNotContainsString( 'memberaccess-', $this->htmlPart( $emailer ) );
	}

	public function testSendingIsReportedAsDone(): void {
		$this->assertTrue( $this->send( new SpyEmailer() ) );
	}

	public function testNothingIsLoggedWhenTheMailGoesOut(): void {
		$this->sendInvitation();

		$this->assertSame( [], $this->logger->getEntries() );
	}

	public function testAMailTheEmailerRefusesIsReportedAsNotSent(): void {
		$this->assertFalse( $this->send( new SpyEmailer( sendSucceeds: false ) ) );
	}

	public function testFailingToSendIsLoggedAsAWarning(): void {
		$this->send( new SpyEmailer( sendSucceeds: false ) );

		$this->assertNotSame( [], $this->logger->getEntriesAtLevel( 'warning' ) );
	}

	public function testLogOfAFailedSendDoesNotContainTheAddress(): void {
		$this->send( new SpyEmailer( sendSucceeds: false ) );

		$this->assertStringNotContainsString( self::RECIPIENT, $this->logger->getLog() );
	}

	private function sendInvitation(): SpyEmailer {
		return $this->sendInvitationTo( self::RECIPIENT );
	}

	private function sendInvitationTo( string $address ): SpyEmailer {
		$emailer = new SpyEmailer();

		$this->sendTo( $emailer, $address );

		return $emailer;
	}

	private function send( SpyEmailer $emailer ): bool {
		return $this->sendTo( $emailer, self::RECIPIENT );
	}

	private function sendTo( SpyEmailer $emailer, string $address ): bool {
		$email = NormalizedEmail::fromString( $address );

		$this->assertNotNull( $email );

		return ( new MediaWikiInvitationMailer(
			emailer: $emailer,
			sender: new MailAddress( self::SENDER ),
			templates: new TemplateParser( __DIR__ . '/../../../templates' ),
			contentLanguage: $this->getServiceContainer()->getContentLanguage(),
			siteName: $this->getConfVar( MainConfigNames::Sitename ),
			loginUrl: self::LOGIN_URL,
			logger: $this->logger
		) )->sendInvitation( $email );
	}

	/**
	 * Asserted on rather than coerced, since some of the claims made about the formatted part are
	 * negative ones, which a part that was never sent would satisfy.
	 */
	private function htmlPart( SpyEmailer $emailer ): string {
		$html = $emailer->getSentMails()[0]['bodyHtml'] ?? null;

		$this->assertIsString( $html, 'no formatted part was sent' );
		$this->assertNotSame( '', $html );

		return $html;
	}

}
