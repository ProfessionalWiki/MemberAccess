<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\CodeRequestOutcome;
use ProfessionalWiki\MemberAccess\Application\CodeVerificationOutcome;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\MemberAccessExtension
 */
class MemberAccessExtensionTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		MemberAccessExtension::getInstance()->setStashOverride( new HashBagOStuff() );
	}

	protected function tearDown(): void {
		MemberAccessExtension::getInstance()->setStashOverride( null );

		parent::tearDown();
	}

	public function testConfiguredDefaultsAreReadable(): void {
		$extension = MemberAccessExtension::getInstance();

		$this->assertSame( 'reader', $extension->getReaderGroup() );
		$this->assertSame( 600, $extension->newCodeLifetime()->inSeconds );
		$this->assertSame( 5, $extension->getCodeAttemptLimit() );
	}

	public function testSenderFallsBackToThePasswordSender(): void {
		$this->overrideConfigValue( 'PasswordSender', 'wiki@example.com' );

		$this->assertSame( 'wiki@example.com', MemberAccessExtension::getInstance()->getSenderAddress()->address );
	}

	public function testConfiguredSenderOverridesThePasswordSender(): void {
		$this->overrideConfigValue( 'PasswordSender', 'wiki@example.com' );
		$this->overrideConfigValue( 'MemberAccessSenderAddress', 'members@example.com' );

		$this->assertSame( 'members@example.com', MemberAccessExtension::getInstance()->getSenderAddress()->address );
	}

	public function testCodeRequestedThroughTheWiredUseCaseCanBeVerified(): void {
		$this->allowAddress( 'jane@example.com' );

		$request = MemberAccessExtension::getInstance()->newRequestCodeUseCase()
			->requestCode( 'jane@example.com', '198.51.100.7' );

		$this->assertSame( CodeRequestOutcome::Accepted, $request->outcome );
		$this->assertSame(
			CodeVerificationOutcome::RetryableFailure,
			MemberAccessExtension::getInstance()->newVerifyCodeUseCase()
				->verify( (string)$request->handle, '00000000' )->outcome
		);
	}

	public function testAllowlistedAddressMatchesThroughTheWiredMatcher(): void {
		$this->allowAddress( 'jane@example.com' );

		$this->assertSame(
			'Acme',
			MemberAccessExtension::getInstance()->newAllowlistMatcher()->matchEmail( 'jane@example.com' )?->name
		);
	}

	private function allowAddress( string $address ): void {
		$extension = MemberAccessExtension::getInstance();
		$value = AllowlistValue::fromString( $address );

		$this->assertNotNull( $value );

		$extension->newAllowlistRepository()->addEntry(
			groupId: $extension->newMemberGroupRepository()->createGroup( 'Acme' )->id,
			value: $value,
			actorId: 1
		);
	}

}
