<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Persistence\DeferredCodeMailer;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyCodeMailer;
use Wikimedia\ScopedCallback;

/**
 * @covers \ProfessionalWiki\MemberAccess\Persistence\DeferredCodeMailer
 */
class DeferredCodeMailerTest extends MediaWikiIntegrationTestCase {

	private const string EMAIL = 'jane@example.com';
	private const string CODE = '12345678';

	private SpyCodeMailer $mailer;

	protected function setUp(): void {
		parent::setUp();

		$this->mailer = new SpyCodeMailer();
	}

	public function testMailWaitsForTheDeferredUpdates(): void {
		$this->requestSend();

		$this->assertSame( [], $this->mailer->getSentMails() );
	}

	public function testMailGoesOutWithTheDeferredUpdates(): void {
		$this->requestSend();

		DeferredUpdates::doUpdates();

		$this->assertSame(
			[ [ 'email' => self::EMAIL, 'code' => self::CODE, 'expiryInMinutes' => 10 ] ],
			$this->mailer->getSentMails()
		);
	}

	/**
	 * Command line requests run deferred updates as soon as they are queued, which a web request
	 * does not, so the queueing has to be observed with that behaviour switched off.
	 */
	private function requestSend(): void {
		$email = NormalizedEmail::fromString( self::EMAIL );

		$this->assertNotNull( $email );

		$scope = DeferredUpdates::preventOpportunisticUpdates();

		( new DeferredCodeMailer( mailer: $this->mailer ) )->sendCode( $email, self::CODE, 10 );

		ScopedCallback::consume( $scope );
	}

}
