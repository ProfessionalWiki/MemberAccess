<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\REST;

use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\ResponseInterface;
use MediaWiki\SpecialPage\SpecialPage;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyEmailer;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\REST\SendInvitationApi
 */
class InvitationApiTest extends RestApiTestCase {

	private const string ADDRESS = 'jane@example.com';

	private SpyEmailer $emailer;
	private int $groupId;
	private int $addressEntryId;
	private int $domainEntryId;

	protected function setUp(): void {
		parent::setUp();

		$this->emailer = new SpyEmailer();
		$this->setService( 'Emailer', $this->emailer );
		$this->overrideConfigValue( 'MemberAccessCodeLogin', 'allowlisted' );

		$this->groupId = $this->newGroup( 'Acme' )->id;
		$this->addressEntryId = $this->newEntry( $this->groupId, self::ADDRESS );
		$this->domainEntryId = $this->newEntry( $this->groupId, '@example.com' );
	}

	public function testInvitingAnAddressIsAccepted(): void {
		$response = $this->invite( $this->addressEntryId );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( $this->addressEntryId, $this->bodyOf( $response )['id'] );
	}

	public function testInvitationGoesToTheEntrysAddress(): void {
		$this->invite( $this->addressEntryId );

		$this->assertSame( [ self::ADDRESS ], array_column( $this->emailer->getSentMails(), 'to' ) );
	}

	public function testEntryThatWasNeverInvitedIsListedWithoutOne(): void {
		$this->assertNull( $this->listedInvitation( $this->addressEntryId ) );
	}

	public function testSentInvitationIsListedWithTheEntry(): void {
		$invited = $this->bodyOf( $this->invite( $this->addressEntryId ) )['invited'] ?? null;

		$this->assertNotNull( $invited );
		$this->assertSame( $invited, $this->listedInvitation( $this->addressEntryId ) );
	}

	public function testInvitationIsListedAsAnIso8601Timestamp(): void {
		$this->invite( $this->addressEntryId );

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			(string)$this->listedInvitation( $this->addressEntryId )
		);
	}

	/**
	 * An invitation that went missing has to be repeatable, so asking again sends again rather than
	 * answering that one already went.
	 */
	public function testInvitingAgainSendsAgain(): void {
		$this->invite( $this->addressEntryId );

		$response = $this->invite( $this->addressEntryId );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertCount( 2, $this->emailer->getSentMails() );
	}

	/**
	 * The endpoint takes no body, so a request carrying none is not a malformed one.
	 */
	public function testInvitingWithAnEmptyBodyIsAccepted(): void {
		$response = $this->runHandler(
			MemberAccessExtension::newSendInvitationApi(),
			$this->emptyBodyRequest( [ 'id' => (string)$this->addressEntryId ] )
		);

		$this->assertSame( 200, $response->getStatusCode() );
	}

	/**
	 * The URL is built by the wiring rather than by the mailer, so what the mail actually points at
	 * is only asserted here.
	 */
	public function testTheMailPointsAtTheWikisLoginPage(): void {
		$this->invite( $this->addressEntryId );

		$this->assertStringContainsString(
			SpecialPage::getTitleFor( 'Userlogin' )->getCanonicalURL(),
			$this->emailer->getSentMails()[0]['bodyText']
		);
	}

	public function testInvitingAnEntryThatIsNotThereIsRefused(): void {
		$this->assertError( 'entry_not_found', 404, $this->invite( 12345 ) );
	}

	public function testInvitingADomainIsRefused(): void {
		$this->assertError( 'not_an_address', 400, $this->invite( $this->domainEntryId ) );
	}

	public function testInvitingADomainSendsNothing(): void {
		$this->invite( $this->domainEntryId );

		$this->assertSame( [], $this->emailer->getSentMails() );
	}

	public function testInvitingIsRefusedWhileTheCodeRouteIsOff(): void {
		$this->overrideConfigValue( 'MemberAccessCodeLogin', 'off' );

		$this->assertError( 'code_login_off', 409, $this->invite( $this->addressEntryId ) );
	}

	/**
	 * A setting nobody recognises is read as off everywhere else, so an invitation cannot be the
	 * one thing that takes it for a route.
	 */
	public function testInvitingIsRefusedWhileTheCodeRouteSettingIsNotUnderstood(): void {
		$this->overrideConfigValue( 'MemberAccessCodeLogin', 'yes-please' );

		$this->assertError( 'code_login_off', 409, $this->invite( $this->addressEntryId ) );
	}

	public function testMailTheWikiCannotSendIsReported(): void {
		$this->setService( 'Emailer', new SpyEmailer( sendSucceeds: false ) );

		$this->assertError( 'invitation_not_sent', 500, $this->invite( $this->addressEntryId ) );
	}

	public function testNothingIsRecordedWhenTheMailCannotBeSent(): void {
		$this->setService( 'Emailer', new SpyEmailer( sendSucceeds: false ) );

		$this->invite( $this->addressEntryId );

		$this->assertNull( $this->listedInvitation( $this->addressEntryId ) );
	}

	public function testInvitingByACallerWithoutTheRightSendsNothing(): void {
		$response = $this->invite( $this->addressEntryId, $this->outsider() );

		$this->assertError( 'permission_denied', 403, $response );
		$this->assertSame( [], $this->emailer->getSentMails() );
	}

	private function invite( int $entryId, ?Authority $authority = null ): ResponseInterface {
		return $this->runHandler(
			MemberAccessExtension::newSendInvitationApi(),
			$this->newRequest( 'POST', [], [ 'id' => (string)$entryId ] ),
			$authority
		);
	}

	private function listedInvitation( int $entryId ): ?string {
		return array_column( $this->listEntries( $this->groupId ), 'invited', 'id' )[$entryId] ?? null;
	}

}
