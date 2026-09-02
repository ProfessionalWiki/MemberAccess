<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\CodeLoginMode;
use ProfessionalWiki\MemberAccess\Application\InvitationOutcome;
use ProfessionalWiki\MemberAccess\Application\InvitationResult;
use ProfessionalWiki\MemberAccess\Application\SendInvitationUseCase;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryAllowlistRepository;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryMemberGroupRepository;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyInvitationMailer;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\SendInvitationUseCase
 * @covers \ProfessionalWiki\MemberAccess\Application\InvitationResult
 */
class SendInvitationUseCaseTest extends TestCase {

	private const string ADDRESS = 'jane@example.com';
	private const int ADMIN_ID = 42;

	private InMemoryAllowlistRepository $allowlist;
	private SpyInvitationMailer $mailer;
	private SpyLogger $logger;
	private int $addressEntryId;
	private int $domainEntryId;

	protected function setUp(): void {
		$groups = new InMemoryMemberGroupRepository();
		$this->allowlist = new InMemoryAllowlistRepository( $groups );
		$this->mailer = new SpyInvitationMailer();
		$this->logger = new SpyLogger();

		$groupId = $groups->createGroup( 'Acme' )->id;
		$this->addressEntryId = $this->addEntry( $groupId, self::ADDRESS );
		$this->domainEntryId = $this->addEntry( $groupId, '@example.com' );
	}

	public function testInvitationIsSentToTheEntrysAddress(): void {
		$result = $this->sendInvitation( $this->addressEntryId );

		$this->assertSame( InvitationOutcome::Sent, $result->outcome );
		$this->assertSame( [ self::ADDRESS ], $this->mailer->getInvitedAddresses() );
	}

	public function testSentInvitationIsRecordedOnTheEntry(): void {
		$result = $this->sendInvitation( $this->addressEntryId );
		$recorded = $this->allowlist->getEntry( $this->addressEntryId )?->invitationTimestamp;

		$this->assertNotNull( $recorded );
		$this->assertSame( $recorded, $result->invitationTimestamp );
	}

	/**
	 * What the entry ends up carrying is what the last invitation recorded, not what the first did.
	 */
	public function testInvitingAgainReplacesWhatWasRecorded(): void {
		$this->sendInvitation( $this->addressEntryId );

		$second = $this->sendInvitation( $this->addressEntryId );

		$this->assertSame(
			$second->invitationTimestamp,
			$this->allowlist->getEntry( $this->addressEntryId )?->invitationTimestamp
		);
	}

	public function testInvitingAgainSendsAgain(): void {
		$this->sendInvitation( $this->addressEntryId );
		$this->sendInvitation( $this->addressEntryId );

		$this->assertSame( [ self::ADDRESS, self::ADDRESS ], $this->mailer->getInvitedAddresses() );
	}

	public function testEntryThatIsNotThereIsRefused(): void {
		$result = $this->sendInvitation( 12345 );

		$this->assertSame( InvitationOutcome::EntryNotFound, $result->outcome );
	}

	public function testDomainEntryIsRefused(): void {
		$result = $this->sendInvitation( $this->domainEntryId );

		$this->assertSame( InvitationOutcome::NotAnAddress, $result->outcome );
	}

	/**
	 * A domain names nobody, so there is nothing to attempt: the refusal comes before the mailer is
	 * reached rather than from it.
	 */
	public function testDomainEntryIsRefusedWithoutAttemptingAMail(): void {
		$this->sendInvitation( $this->domainEntryId );

		$this->assertSame( [], $this->mailer->getInvitedAddresses() );
	}

	public function testInvitingIsRefusedWhileTheCodeRouteIsOff(): void {
		$result = $this->sendInvitation( $this->addressEntryId, mode: CodeLoginMode::Off );

		$this->assertSame( InvitationOutcome::CodeLoginOff, $result->outcome );
	}

	public function testNoMailIsSentWhileTheCodeRouteIsOff(): void {
		$this->sendInvitation( $this->addressEntryId, mode: CodeLoginMode::Off );

		$this->assertSame( [], $this->mailer->getInvitedAddresses() );
	}

	/**
	 * An open route issues codes too, so an invitation to use one is as true there as on a route
	 * held to the allowlist.
	 */
	public function testInvitingIsAllowedWhileTheRouteAdmitsEveryAddress(): void {
		$result = $this->sendInvitation( $this->addressEntryId, mode: CodeLoginMode::Open );

		$this->assertSame( InvitationOutcome::Sent, $result->outcome );
	}

	public function testMailThatWasNotAcceptedIsReported(): void {
		$result = $this->sendFailingInvitation( $this->addressEntryId );

		$this->assertSame( InvitationOutcome::SendFailed, $result->outcome );
	}

	/**
	 * Nothing is written down for a mail that did not go, so that what the entry says about being
	 * invited stays true.
	 */
	public function testNothingIsRecordedWhenTheMailWasNotAccepted(): void {
		$this->sendFailingInvitation( $this->addressEntryId );

		$this->assertNull( $this->allowlist->getEntry( $this->addressEntryId )?->invitationTimestamp );
	}

	public function testSendingIsLoggedWithoutTheAddress(): void {
		$this->sendInvitation( $this->addressEntryId );

		$this->assertNotSame( [], $this->logger->getEntriesAtLevel( 'info' ) );
		$this->assertStringNotContainsString( self::ADDRESS, $this->logger->getLog() );
	}

	public function testSendingIsLoggedWithWhoAskedForIt(): void {
		$this->sendInvitation( $this->addressEntryId );

		$this->assertStringContainsString( '"performer":' . self::ADMIN_ID, $this->logger->getLog() );
	}

	public function testFailingToSendIsLoggedAsAWarning(): void {
		$this->sendFailingInvitation( $this->addressEntryId );

		$this->assertNotSame( [], $this->logger->getEntriesAtLevel( 'warning' ) );
		$this->assertStringNotContainsString( self::ADDRESS, $this->logger->getLog() );
	}

	public function testRefusedInvitationIsNotLoggedAsSent(): void {
		$this->sendInvitation( $this->domainEntryId );

		$this->assertSame( [], $this->logger->getEntries() );
	}

	private function addEntry( int $groupId, string $value ): int {
		$parsed = AllowlistValue::fromString( $value );

		$this->assertNotNull( $parsed );

		$entry = $this->allowlist->addEntry( groupId: $groupId, value: $parsed, actorId: 1 );

		$this->assertNotNull( $entry );

		return $entry->id;
	}

	private function sendInvitation(
		int $entryId,
		CodeLoginMode $mode = CodeLoginMode::Allowlisted
	): InvitationResult {
		return $this->newUseCase( $this->mailer, $mode )->sendInvitation( $entryId, self::ADMIN_ID );
	}

	private function sendFailingInvitation( int $entryId ): InvitationResult {
		return $this->newUseCase( new SpyInvitationMailer( sendSucceeds: false ), CodeLoginMode::Allowlisted )
			->sendInvitation( $entryId, self::ADMIN_ID );
	}

	private function newUseCase( SpyInvitationMailer $mailer, CodeLoginMode $mode ): SendInvitationUseCase {
		return new SendInvitationUseCase(
			mode: $mode,
			allowlist: $this->allowlist,
			mailer: $mailer,
			logger: $this->logger
		);
	}

}
