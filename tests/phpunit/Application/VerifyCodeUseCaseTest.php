<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\CodeHasher;
use ProfessionalWiki\MemberAccess\Application\CodeLifetime;
use ProfessionalWiki\MemberAccess\Application\CodeVerificationOutcome;
use ProfessionalWiki\MemberAccess\Application\IssuedCode;
use ProfessionalWiki\MemberAccess\Application\VerifyCodeUseCase;
use ProfessionalWiki\MemberAccess\Persistence\StashCodeRepository;
use ProfessionalWiki\MemberAccess\Persistence\StashCounterStore;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\UnavailableStash;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\VerifyCodeUseCase
 */
class VerifyCodeUseCaseTest extends TestCase {

	private const string HANDLE = 'the-handle';
	private const string CODE = '12345678';
	private const string EMAIL = 'jane@example.com';

	private HashBagOStuff $stash;
	private SpyLogger $logger;

	protected function setUp(): void {
		$this->stash = new HashBagOStuff();
		$this->logger = new SpyLogger();

		$this->issueCode();
	}

	public function testCorrectCodePasses(): void {
		$result = $this->newUseCase()->verify( self::HANDLE, self::CODE );

		$this->assertSame( CodeVerificationOutcome::Pass, $result->outcome );
		$this->assertSame( self::EMAIL, $result->email );
	}

	public function testCodeCanOnlyBeUsedOnce(): void {
		$useCase = $this->newUseCase();
		$useCase->verify( self::HANDLE, self::CODE );

		$this->assertSame(
			CodeVerificationOutcome::Burned,
			$useCase->verify( self::HANDLE, self::CODE )->outcome
		);
	}

	public function testWrongCodeCanBeRetried(): void {
		$result = $this->newUseCase()->verify( self::HANDLE, '00000000' );

		$this->assertSame( CodeVerificationOutcome::RetryableFailure, $result->outcome );
		$this->assertSame( 4, $result->attemptsRemaining );
	}

	public function testRemainingAttemptsCountDown(): void {
		$useCase = $this->newUseCase();
		$useCase->verify( self::HANDLE, '00000000' );

		$this->assertSame( 3, $useCase->verify( self::HANDLE, '00000000' )->attemptsRemaining );
	}

	public function testCorrectCodeStillPassesAfterAWrongOne(): void {
		$useCase = $this->newUseCase();
		$useCase->verify( self::HANDLE, '00000000' );

		$this->assertSame( CodeVerificationOutcome::Pass, $useCase->verify( self::HANDLE, self::CODE )->outcome );
	}

	public function testWrongAttemptJustBeforeTheCapCanStillBeRetried(): void {
		$useCase = $this->newUseCase();
		$this->failVerification( $useCase, 3 );

		$result = $useCase->verify( self::HANDLE, '00000000' );

		$this->assertSame( CodeVerificationOutcome::RetryableFailure, $result->outcome );
		$this->assertSame( 1, $result->attemptsRemaining );
	}

	public function testCorrectCodeOnTheLastAllowedAttemptPasses(): void {
		$useCase = $this->newUseCase();
		$this->failVerification( $useCase, 4 );

		$this->assertSame( CodeVerificationOutcome::Pass, $useCase->verify( self::HANDLE, self::CODE )->outcome );
	}

	public function testCodeIsBurnedOnTheFifthWrongAttempt(): void {
		$useCase = $this->newUseCase();
		$this->failVerification( $useCase, 4 );

		$this->assertSame(
			CodeVerificationOutcome::Burned,
			$useCase->verify( self::HANDLE, '00000000' )->outcome
		);
	}

	public function testBurnedCodeNoLongerPasses(): void {
		$useCase = $this->newUseCase();
		$this->failVerification( $useCase, 5 );

		$this->assertSame( CodeVerificationOutcome::Burned, $useCase->verify( self::HANDLE, self::CODE )->outcome );
	}

	public function testWrongAttemptsAreCountedPerIssuedCode(): void {
		$useCase = $this->newUseCase();
		$this->failVerification( $useCase, 5 );

		$this->issueCode( handle: 'other-handle' );

		$result = $useCase->verify( 'other-handle', '00000000' );

		$this->assertSame( CodeVerificationOutcome::RetryableFailure, $result->outcome );
		$this->assertSame( 4, $result->attemptsRemaining );
	}

	public function testFreshCodePassesAfterAnotherOneWasBurned(): void {
		$useCase = $this->newUseCase();
		$this->failVerification( $useCase, 5 );

		$this->issueCode( handle: 'other-handle' );

		$this->assertSame(
			CodeVerificationOutcome::Pass,
			$useCase->verify( 'other-handle', self::CODE )->outcome
		);
	}

	public function testUnknownHandleIsBurned(): void {
		$this->assertSame(
			CodeVerificationOutcome::Burned,
			$this->newUseCase()->verify( 'never-issued', self::CODE )->outcome
		);
	}

	public function testExpiredCodeIsBurned(): void {
		$time = 1000.0;
		$this->stash->setMockTime( $time );
		$this->issueCode( handle: 'timed-handle' );

		$time += 601;

		$this->assertSame(
			CodeVerificationOutcome::Burned,
			$this->newUseCase()->verify( 'timed-handle', self::CODE )->outcome
		);
	}

	/**
	 * Attempts that nobody counts are attempts without a cap, so the code goes rather than the cap.
	 */
	public function testCodeIsBurnedWhileTheAttemptsCannotBeCounted(): void {
		$useCase = $this->newUseCase( new UnavailableStash() );

		$this->assertSame( CodeVerificationOutcome::Burned, $useCase->verify( self::HANDLE, '00000000' )->outcome );
	}

	public function testPassIsLogged(): void {
		$this->newUseCase()->verify( self::HANDLE, self::CODE );

		$this->assertNotSame( '', $this->logger->getLog() );
	}

	public function testFailureIsLogged(): void {
		$this->newUseCase()->verify( self::HANDLE, '00000000' );

		$this->assertNotSame( '', $this->logger->getLog() );
	}

	public function testLogNeverContainsTheAddressOrTheCode(): void {
		$useCase = $this->newUseCase();
		$useCase->verify( self::HANDLE, '00000000' );
		$useCase->verify( self::HANDLE, self::CODE );

		$this->assertStringNotContainsString( self::EMAIL, $this->logger->getLog() );
		$this->assertStringNotContainsString( self::CODE, $this->logger->getLog() );
	}

	private function issueCode( string $handle = self::HANDLE ): void {
		( new StashCodeRepository( stash: $this->stash ) )->store(
			handle: $handle,
			code: new IssuedCode( email: self::EMAIL, codeHash: $this->newHasher()->hash( self::CODE ) ),
			ttlInSeconds: 600
		);
	}

	private function failVerification( VerifyCodeUseCase $useCase, int $times ): void {
		foreach ( range( 1, $times ) as $ignored ) {
			$useCase->verify( self::HANDLE, '00000000' );
		}
	}

	private function newUseCase( ?BagOStuff $counterStash = null ): VerifyCodeUseCase {
		return new VerifyCodeUseCase(
			codes: new StashCodeRepository( stash: $this->stash ),
			counters: new StashCounterStore( stash: $counterStash ?? $this->stash, logger: $this->logger ),
			hasher: $this->newHasher(),
			logger: $this->logger,
			codeLifetime: new CodeLifetime( 600 ),
			attemptLimit: 5
		);
	}

	private function newHasher(): CodeHasher {
		return new CodeHasher( secret: 'test-secret' );
	}

}
