<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\RequestThrottle;
use ProfessionalWiki\MemberAccess\Persistence\StashCounterStore;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\UnavailableStash;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\RequestThrottle
 */
class RequestThrottleTest extends TestCase {

	private const string IP = '198.51.100.7';

	private float $time = 1000.0;
	private HashBagOStuff $stash;

	protected function setUp(): void {
		$this->stash = new HashBagOStuff();
		$this->stash->setMockTime( $this->time );
	}

	public function testFirstRequestIsAllowed(): void {
		$this->assertTrue( $this->newThrottle()->recordRequest( $this->email( 'jane@example.com' ), self::IP ) );
	}

	public function testRequestsUpToTheEmailBurstLimitAreAllowed(): void {
		$throttle = $this->newThrottle( emailBurstLimit: 3 );
		$this->request( $throttle, 'jane@example.com', self::IP, 2 );

		$this->assertTrue( $throttle->recordRequest( $this->email( 'jane@example.com' ), self::IP ) );
	}

	public function testRequestBeyondTheEmailBurstLimitIsRefused(): void {
		$throttle = $this->newThrottle( emailBurstLimit: 3 );
		$this->request( $throttle, 'jane@example.com', self::IP, 3 );

		$this->assertFalse( $throttle->recordRequest( $this->email( 'jane@example.com' ), self::IP ) );
	}

	public function testEmailBurstLimitAppliesPerAddress(): void {
		$throttle = $this->newThrottle( emailBurstLimit: 3 );
		$this->request( $throttle, 'jane@example.com', self::IP, 3 );

		$this->assertTrue( $throttle->recordRequest( $this->email( 'john@example.com' ), self::IP ) );
	}

	public function testEmailBurstLimitLiftsAfterFifteenMinutes(): void {
		$throttle = $this->newThrottle( emailBurstLimit: 3 );
		$this->request( $throttle, 'jane@example.com', self::IP, 3 );

		$this->time += 901;

		$this->assertTrue( $throttle->recordRequest( $this->email( 'jane@example.com' ), self::IP ) );
	}

	public function testEmailDailyLimitSurvivesTheBurstWindow(): void {
		$throttle = $this->newThrottle( emailBurstLimit: 3, emailDailyLimit: 4 );

		foreach ( range( 1, 4 ) as $ignored ) {
			$throttle->recordRequest( $this->email( 'jane@example.com' ), self::IP );
			$this->time += 901;
		}

		$this->assertFalse( $throttle->recordRequest( $this->email( 'jane@example.com' ), self::IP ) );
	}

	public function testEmailDailyLimitLiftsAfterADay(): void {
		$throttle = $this->newThrottle( emailBurstLimit: 100, emailDailyLimit: 2 );
		$this->request( $throttle, 'jane@example.com', self::IP, 2 );

		$this->time += 86401;

		$this->assertTrue( $throttle->recordRequest( $this->email( 'jane@example.com' ), self::IP ) );
	}

	public function testRequestBeyondTheIpBurstLimitIsRefused(): void {
		$throttle = $this->newThrottle( ipBurstLimit: 2 );
		$throttle->recordRequest( $this->email( 'first@example.com' ), self::IP );
		$throttle->recordRequest( $this->email( 'second@example.com' ), self::IP );

		$this->assertFalse( $throttle->recordRequest( $this->email( 'third@example.com' ), self::IP ) );
	}

	public function testIpBurstLimitAppliesPerAddress(): void {
		$throttle = $this->newThrottle( ipBurstLimit: 2 );
		$throttle->recordRequest( $this->email( 'first@example.com' ), self::IP );
		$throttle->recordRequest( $this->email( 'second@example.com' ), self::IP );

		$this->assertTrue( $throttle->recordRequest( $this->email( 'third@example.com' ), '203.0.113.9' ) );
	}

	public function testRequestBeyondTheIpDailyLimitIsRefused(): void {
		$throttle = $this->newThrottle( ipBurstLimit: 100, ipDailyLimit: 2 );
		$throttle->recordRequest( $this->email( 'first@example.com' ), self::IP );
		$throttle->recordRequest( $this->email( 'second@example.com' ), self::IP );

		$this->assertFalse( $throttle->recordRequest( $this->email( 'third@example.com' ), self::IP ) );
	}

	public function testRefusedRequestStillCountsTowardsTheDailyLimit(): void {
		$throttle = $this->newThrottle( emailBurstLimit: 2, emailDailyLimit: 3 );
		$this->request( $throttle, 'jane@example.com', self::IP, 3 );

		$this->time += 901;

		$this->assertFalse( $throttle->recordRequest( $this->email( 'jane@example.com' ), self::IP ) );
	}

	/**
	 * Letting requests through while nothing counts them would take the throttle off altogether.
	 */
	public function testRequestIsRefusedWhileTheCountersCannotBeReached(): void {
		$throttle = $this->newThrottle( stash: new UnavailableStash() );

		$this->assertFalse( $throttle->recordRequest( $this->email( 'jane@example.com' ), self::IP ) );
	}

	private function newThrottle(
		int $emailBurstLimit = 3,
		int $emailDailyLimit = 10,
		int $ipBurstLimit = 10,
		int $ipDailyLimit = 50,
		?BagOStuff $stash = null
	): RequestThrottle {
		return new RequestThrottle(
			counters: new StashCounterStore( stash: $stash ?? $this->stash, logger: new SpyLogger() ),
			emailBurstLimit: $emailBurstLimit,
			emailDailyLimit: $emailDailyLimit,
			ipBurstLimit: $ipBurstLimit,
			ipDailyLimit: $ipDailyLimit
		);
	}

	private function request( RequestThrottle $throttle, string $email, string $ip, int $times ): void {
		foreach ( range( 1, $times ) as $ignored ) {
			$throttle->recordRequest( $this->email( $email ), $ip );
		}
	}

	private function email( string $address ): NormalizedEmail {
		$email = NormalizedEmail::fromString( $address );

		$this->assertNotNull( $email );

		return $email;
	}

}
