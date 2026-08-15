<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistMatcher;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\CodeHasher;
use ProfessionalWiki\MemberAccess\Application\CodeLifetime;
use ProfessionalWiki\MemberAccess\Application\CodeRequestOutcome;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\RequestCodeUseCase;
use ProfessionalWiki\MemberAccess\Application\RequestThrottle;
use ProfessionalWiki\MemberAccess\Persistence\StashCodeRepository;
use ProfessionalWiki\MemberAccess\Persistence\StashCounterStore;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\FixedSecretGenerator;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryAllowlistRepository;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryMemberGroupRepository;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryMemberRepository;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyCodeMailer;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\RequestCodeUseCase
 */
class RequestCodeUseCaseTest extends TestCase {

	private const string IP = '198.51.100.7';
	private const string LISTED_EMAIL = 'jane@example.com';
	private const string UNLISTED_EMAIL = 'john@example.net';

	private HashBagOStuff $stash;
	private InMemoryMemberGroupRepository $groups;
	private InMemoryAllowlistRepository $allowlist;
	private InMemoryMemberRepository $members;
	private SpyCodeMailer $mailer;
	private SpyLogger $logger;

	protected function setUp(): void {
		$this->stash = new HashBagOStuff();
		$this->groups = new InMemoryMemberGroupRepository();
		$this->allowlist = new InMemoryAllowlistRepository( $this->groups );
		$this->members = new InMemoryMemberRepository();
		$this->mailer = new SpyCodeMailer();
		$this->logger = new SpyLogger();

		$value = AllowlistValue::fromString( self::LISTED_EMAIL );
		$this->assertNotNull( $value );
		$this->allowlist->addEntry( $this->groups->createGroup( 'Acme' )->id, $value, 1 );
	}

	public function testListedAddressIsAccepted(): void {
		$result = $this->newUseCase()->requestCode( self::LISTED_EMAIL, self::IP );

		$this->assertSame( CodeRequestOutcome::Accepted, $result->outcome );
		$this->assertNotNull( $result->handle );
	}

	public function testUnlistedAddressGetsTheSameOutcomeAndAHandle(): void {
		$result = $this->newUseCase()->requestCode( self::UNLISTED_EMAIL, self::IP );

		$this->assertSame( CodeRequestOutcome::Accepted, $result->outcome );
		$this->assertNotNull( $result->handle );
	}

	public function testUnlistedAddressGetsACodeItCanFailToVerify(): void {
		$result = $this->newUseCase()->requestCode( self::UNLISTED_EMAIL, self::IP );

		$this->assertNotNull(
			$this->newCodeRepository()->get( (string)$result->handle ),
			'a decoy code must exist, or verification would reveal that the address is unlisted'
		);
	}

	public function testListedAddressReceivesTheCodeByMail(): void {
		$this->newUseCase()->requestCode( self::LISTED_EMAIL, self::IP );

		$this->assertSame(
			[ [ 'email' => self::LISTED_EMAIL, 'code' => '12345678', 'expiryInMinutes' => 10 ] ],
			$this->mailer->getSentMails()
		);
	}

	public function testUnlistedAddressReceivesNoMail(): void {
		$this->newUseCase()->requestCode( self::UNLISTED_EMAIL, self::IP );

		$this->assertSame( [], $this->mailer->getSentMails() );
	}

	public function testDeactivatedMemberReceivesNoMail(): void {
		$this->deactivateTheListedMember();

		$this->newUseCase()->requestCode( self::LISTED_EMAIL, self::IP );

		$this->assertSame( [], $this->mailer->getSentMails() );
	}

	public function testDeactivatedMemberGetsTheSameOutcomeAndAHandle(): void {
		$this->deactivateTheListedMember();

		$result = $this->newUseCase()->requestCode( self::LISTED_EMAIL, self::IP );

		$this->assertSame( CodeRequestOutcome::Accepted, $result->outcome );
		$this->assertNotNull( $result->handle );
	}

	public function testDeactivatedMemberGetsACodeItCanFailToVerify(): void {
		$this->deactivateTheListedMember();

		$result = $this->newUseCase()->requestCode( self::LISTED_EMAIL, self::IP );

		$this->assertNotNull(
			$this->newCodeRepository()->get( (string)$result->handle ),
			'a decoy code must exist, or verification would reveal that the member is deactivated'
		);
	}

	public function testRosterIsConsultedEvenForAnAddressThatIsNotAdmitted(): void {
		$this->newUseCase()->requestCode( self::UNLISTED_EMAIL, self::IP );

		$this->assertSame(
			1,
			$this->members->getAddressLookupCount(),
			'the same work must be done whatever the answer, or the response time gives it away'
		);
	}

	public function testMemberWhoIsStillActiveReceivesTheCodeByMail(): void {
		$this->recordTheListedMember();

		$this->newUseCase()->requestCode( self::LISTED_EMAIL, self::IP );

		$this->assertCount( 1, $this->mailer->getSentMails() );
	}

	public function testAddressAtAListedDomainReceivesTheCodeByMail(): void {
		$value = AllowlistValue::fromString( '@example.org' );
		$this->assertNotNull( $value );
		$this->allowlist->addEntry( $this->groups->createGroup( 'Example org' )->id, $value, 1 );

		$this->newUseCase()->requestCode( 'someone@example.org', self::IP );

		$this->assertCount( 1, $this->mailer->getSentMails() );
	}

	public function testStoredCodeIsHashed(): void {
		$result = $this->newUseCase()->requestCode( self::LISTED_EMAIL, self::IP );

		$this->assertStringNotContainsString(
			'12345678',
			(string)$this->newCodeRepository()->get( (string)$result->handle )?->codeHash
		);
	}

	public function testStoredCodeCarriesTheNormalizedAddress(): void {
		$result = $this->newUseCase()->requestCode( '  JANE@Example.COM ', self::IP );

		$this->assertSame(
			self::LISTED_EMAIL,
			$this->newCodeRepository()->get( (string)$result->handle )?->email
		);
	}

	public function testCodeExpiresAfterItsLifetime(): void {
		$time = 1000.0;
		$this->stash->setMockTime( $time );

		$result = $this->newUseCase()->requestCode( self::LISTED_EMAIL, self::IP );
		$time += 601;

		$this->assertNull( $this->newCodeRepository()->get( (string)$result->handle ) );
	}

	public function testTextThatIsNotAnAddressIsRejected(): void {
		$result = $this->newUseCase()->requestCode( 'not-an-address', self::IP );

		$this->assertSame( CodeRequestOutcome::InvalidEmail, $result->outcome );
		$this->assertNull( $result->handle );
	}

	public function testRequestBeyondTheThrottleIsRefused(): void {
		$useCase = $this->newUseCase();

		foreach ( range( 1, 3 ) as $ignored ) {
			$useCase->requestCode( self::LISTED_EMAIL, self::IP );
		}

		$this->assertSame(
			CodeRequestOutcome::Throttled,
			$useCase->requestCode( self::LISTED_EMAIL, self::IP )->outcome
		);
	}

	public function testThrottledRequestSendsNoMail(): void {
		$useCase = $this->newUseCase();

		foreach ( range( 1, 4 ) as $ignored ) {
			$useCase->requestCode( self::LISTED_EMAIL, self::IP );
		}

		$this->assertCount( 3, $this->mailer->getSentMails() );
	}

	public function testEveryRequestIsLogged(): void {
		$this->newUseCase()->requestCode( self::LISTED_EMAIL, self::IP );

		$this->assertNotSame( '', $this->logger->getLog() );
	}

	public function testUnlistedRequestIsLogged(): void {
		$this->newUseCase()->requestCode( self::UNLISTED_EMAIL, self::IP );

		$this->assertNotSame( '', $this->logger->getLog() );
	}

	public function testThrottleHitIsLogged(): void {
		$useCase = $this->newUseCase();

		foreach ( range( 1, 3 ) as $ignored ) {
			$useCase->requestCode( self::LISTED_EMAIL, self::IP );
		}
		$entriesBefore = count( $this->logger->getEntries() );

		$useCase->requestCode( self::LISTED_EMAIL, self::IP );

		$this->assertGreaterThan( $entriesBefore, count( $this->logger->getEntries() ) );
	}

	public function testLogNeverContainsTheAddressOrTheCode(): void {
		$useCase = $this->newUseCase();
		$useCase->requestCode( self::LISTED_EMAIL, self::IP );
		$useCase->requestCode( self::UNLISTED_EMAIL, self::IP );

		$this->assertStringNotContainsString( self::LISTED_EMAIL, $this->logger->getLog() );
		$this->assertStringNotContainsString( self::UNLISTED_EMAIL, $this->logger->getLog() );
		$this->assertStringNotContainsString( '12345678', $this->logger->getLog() );
	}

	private function recordTheListedMember(): void {
		$email = NormalizedEmail::fromString( self::LISTED_EMAIL );

		$this->assertNotNull( $email );

		$this->members->recordMember( userId: 1, email: $email, groupId: 1 );
	}

	private function deactivateTheListedMember(): void {
		$this->recordTheListedMember();
		$this->members->deactivateMember( 1 );
	}

	private function newUseCase(): RequestCodeUseCase {
		return new RequestCodeUseCase(
			matcher: new AllowlistMatcher( allowlist: $this->allowlist ),
			members: $this->members,
			throttle: new RequestThrottle(
				counters: new StashCounterStore( stash: $this->stash, logger: $this->logger ),
				emailBurstLimit: 3,
				emailDailyLimit: 10,
				ipBurstLimit: 10,
				ipDailyLimit: 50
			),
			generator: new FixedSecretGenerator(),
			hasher: new CodeHasher( secret: 'test-secret' ),
			codes: $this->newCodeRepository(),
			mailer: $this->mailer,
			logger: $this->logger,
			codeLifetime: new CodeLifetime( 600 )
		);
	}

	private function newCodeRepository(): StashCodeRepository {
		return new StashCodeRepository( stash: $this->stash );
	}

}
