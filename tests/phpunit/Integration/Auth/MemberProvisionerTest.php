<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\MemberProvisioner;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyLogger;
use RuntimeException;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\MemberProvisioner
 */
class MemberProvisionerTest extends MediaWikiIntegrationTestCase {

	private const MEMBER_EMAIL = 'jane@example.com';
	private const MEMBER_NAME = 'Member AB2345';

	private SpyLogger $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = new SpyLogger();
	}

	public function testMemberIsPlacedInTheReaderGroup(): void {
		$user = $this->newAccount();

		$this->provision( $user );

		$this->assertContains( 'reader', $this->getServiceContainer()->getUserGroupManager()->getUserGroups( $user ) );
	}

	public function testProvisioningStopsWhenTheReaderGroupCannotBeAdded(): void {
		$user = $this->newAccount();
		$this->refuseGroupAdditions();

		$this->expectException( RuntimeException::class );
		$this->provision( $user );
	}

	public function testNoMemberIsRecordedWhenTheReaderGroupCannotBeAdded(): void {
		$user = $this->newAccount();
		$this->refuseGroupAdditions();

		$this->provisionAndSwallowTheFailure( $user );

		$this->assertNull( MemberAccessExtension::getInstance()->newMemberRepository()->getMember( $user->getId(), ReadConsistency::UpToDate ) );
	}

	public function testFailingToAddTheReaderGroupIsLoggedAsAnError(): void {
		$user = $this->newAccount();
		$this->refuseGroupAdditions();

		$this->provisionAndSwallowTheFailure( $user );

		$this->assertCount( 1, $this->logger->getEntriesAtLevel( 'error' ) );
	}

	public function testAddressIsNotConfirmedWhenTheReaderGroupCannotBeAdded(): void {
		$user = $this->newAccount();
		$this->refuseGroupAdditions();

		$this->provisionAndSwallowTheFailure( $user );

		$this->assertFalse( $this->reloaded( $user )->isEmailConfirmed() );
	}

	/**
	 * A group addition is refused by handlers of core's UserAddGroup hook, which other extensions
	 * use to hold group membership to their own rules.
	 */
	private function refuseGroupAdditions(): void {
		$this->setTemporaryHook(
			'UserAddGroup',
			static fn ( User $user, string &$group, ?string &$expiry ): bool => false
		);
	}

	private function provision( User $user ): void {
		$email = NormalizedEmail::fromString( self::MEMBER_EMAIL );

		$this->assertNotNull( $email );

		$this->newProvisioner()->provision( $user, $email, $this->newGroupId() );
	}

	private function provisionAndSwallowTheFailure( User $user ): void {
		try {
			$this->provision( $user );
		} catch ( RuntimeException ) {
		}
	}

	private function newProvisioner(): MemberProvisioner {
		return new MemberProvisioner(
			members: MemberAccessExtension::getInstance()->newMemberRepository(),
			userGroups: $this->getServiceContainer()->getUserGroupManager(),
			logger: $this->logger,
			readerGroup: 'reader'
		);
	}

	private function newGroupId(): int {
		return MemberAccessExtension::getInstance()->newMemberGroupRepository()->createGroup( 'Acme' )->id;
	}

	private function newAccount(): User {
		$user = $this->getServiceContainer()->getUserFactory()->newFromName( self::MEMBER_NAME );

		$this->assertNotNull( $user );
		$user->addToDatabase();

		return $user;
	}

	private function reloaded( User $user ): User {
		$reloaded = $this->getServiceContainer()->getUserFactory()->newFromId( $user->getId() );
		$reloaded->load( $reloaded::READ_LATEST );

		return $reloaded;
	}

}
