<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Authority;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;
use PermissionsError;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\EnterCodeRequest;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Tests\Integration\Auth\AuthenticationProviderRegistration;
use ProfessionalWiki\MemberAccess\Tests\Integration\Auth\CodeRequestSubmission;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\FixedSecretGenerator;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyEmailer;
use SpecialPageExecutor;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * A member's username is their email address, so the core logs that name accounts name the whole
 * roster. This checks each way of reading them is closed to members and open to admins.
 *
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\RegistrationHandler
 */
class MemberLogVisibilityTest extends ApiTestCase {

	use AuthenticationProviderRegistration;
	use CodeRequestSubmission;

	private const CODE = '12345678';
	private const MEMBER_EMAIL = 'jane@example.com';
	private const MEMBER_NAME = 'Jane@example.com';
	private const RETURN_TO_URL = 'https://wiki.example.com/return';

	protected function setUp(): void {
		parent::setUp();

		$this->setService( 'Emailer', new SpyEmailer() );
		$this->registerOurAuthenticationProvider();

		// The members whose log entries these tests are about are made by logging in over the
		// code route, which a wiki has to turn on.
		$this->overrideConfigValue( 'MemberAccessCodeLogin', 'allowlisted' );

		$this->overrideConfigValues( [
			MainConfigNames::NewUserLog => true,
			MainConfigNames::GroupPermissions => array_replace_recursive(
				$this->getConfVar( MainConfigNames::GroupPermissions ),
				[ '*' => [ 'autocreateaccount' => true ] ]
			)
		] );

		MemberAccessExtension::getInstance()->setStashOverride( new HashBagOStuff() );
		MemberAccessExtension::getInstance()->setSecretGeneratorOverride( new FixedSecretGenerator( self::CODE ) );
	}

	protected function tearDown(): void {
		MemberAccessExtension::getInstance()->setStashOverride( null );
		MemberAccessExtension::getInstance()->setSecretGeneratorOverride( null );

		parent::tearDown();
	}

	public function testNewUserLogNamesTheMemberToAnAdmin(): void {
		$this->admitAMember();

		$entries = $this->logEventsFor( $this->admin(), 'newusers' );

		$this->assertSame( [ self::MEMBER_NAME ], array_column( $entries, 'user' ) );
	}

	public function testNewUserLogIsClosedToAMember(): void {
		$this->admitAMember();

		$this->assertSame( [], $this->logEventsFor( $this->member(), 'newusers' ) );
	}

	public function testBlockLogNamesTheDeactivatedMemberToAnAdmin(): void {
		$this->deactivate( $this->admitAMember() );

		$entries = $this->logEventsFor( $this->admin(), 'block' );

		$this->assertSame( [ 'User:' . self::MEMBER_NAME ], array_column( $entries, 'title' ) );
	}

	public function testBlockLogIsClosedToAMember(): void {
		$this->deactivate( $this->admitAMember() );

		$this->assertSame( [], $this->logEventsFor( $this->member(), 'block' ) );
	}

	/**
	 * Removing a member renames their account away from their address, and the rename log records
	 * the address it renamed away from, with the removal as the recorded reason.
	 */
	public function testRenameLogNamesTheRemovedMemberToAnAdmin(): void {
		$this->remove( $this->admitAMember() );

		$entries = $this->logEventsFor( $this->admin(), 'renameuser' );

		$this->assertSame( [ 'User:' . self::MEMBER_NAME ], array_column( $entries, 'title' ) );
		$this->assertSame( [ 'Member removed' ], array_column( $entries, 'comment' ) );
	}

	public function testRenameLogIsClosedToAMember(): void {
		$this->remove( $this->admitAMember() );

		$this->assertSame( [], $this->logEventsFor( $this->member(), 'renameuser' ) );
	}

	/**
	 * Log entries of a restricted type are kept out of recent changes when they are written, so
	 * there is nothing there for the query to filter.
	 */
	public function testDeactivatingAMemberDoesNotShowUpInRecentChanges(): void {
		$this->editPage( 'Visible page', 'Some text' );
		$this->deactivate( $this->admitAMember() );

		$changes = $this->recentChangesFor( $this->member() );

		$this->assertNotSame( [], $changes );
		$this->assertSame( [], array_column( $changes, 'logtype' ) );
	}

	public function testSpecialLogNamesTheMemberToAnAdmin(): void {
		$this->admitAMember();

		$html = $this->specialLogFor( $this->admin(), 'newusers' );

		$this->assertStringContainsString( self::MEMBER_NAME, $html );
	}

	public function testSpecialLogRefusesTheNewUserLogToAMember(): void {
		$this->admitAMember();

		$this->expectException( PermissionsError::class );
		$this->specialLogFor( $this->member(), 'newusers' );
	}

	public function testSpecialLogWithoutAChosenTypeNamesNoMember(): void {
		$this->admitAMember();

		$this->assertStringNotContainsString( self::MEMBER_NAME, $this->specialLogFor( $this->member(), '' ) );
	}

	private function admin(): Authority {
		return $this->getTestSysop()->getAuthority();
	}

	private function member(): Authority {
		return $this->getMutableTestUser( [ 'reader' ] )->getAuthority();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function logEventsFor( Authority $performer, string $type ): array {
		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'list' => 'logevents',
			'letype' => $type,
			'leprop' => 'user|title|type|comment'
		], null, false, $performer );

		return $result['query']['logevents'];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function recentChangesFor( Authority $performer ): array {
		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'list' => 'recentchanges',
			'rcprop' => 'title|loginfo'
		], null, false, $performer );

		return $result['query']['recentchanges'];
	}

	private function specialLogFor( Authority $performer, string $logType ): string {
		[ $html ] = ( new SpecialPageExecutor() )->executeSpecialPage(
			$this->getServiceContainer()->getSpecialPageFactory()->getPage( 'Log' ),
			$logType,
			null,
			null,
			$performer
		);

		return $html;
	}

	private function admitAMember(): User {
		$extension = MemberAccessExtension::getInstance();
		$value = AllowlistValue::fromString( self::MEMBER_EMAIL );

		$this->assertNotNull( $value );

		$extension->newAllowlistRepository()->addEntry(
			groupId: $extension->newMemberGroupRepository()->createGroup( 'Acme' )->id,
			value: $value,
			actorId: 1
		);

		$this->logIn();

		$user = $this->getServiceContainer()->getUserFactory()->newFromName( self::MEMBER_NAME );

		$this->assertNotNull( $user );

		return $user;
	}

	private function logIn(): void {
		$this->getServiceContainer()->getAuthManager()
			->beginAuthentication( $this->submittedCodeRequest( self::MEMBER_EMAIL ), self::RETURN_TO_URL );
		DeferredUpdates::doUpdates();

		$codeEntry = new EnterCodeRequest();
		$codeEntry->memberaccessCode = self::CODE;

		$response = $this->getServiceContainer()->getAuthManager()->continueAuthentication( [ $codeEntry ] );

		$this->assertSame( AuthenticationResponse::PASS, $response->status );

		DeferredUpdates::doUpdates();
	}

	private function remove( User $member ): void {
		MemberAccessExtension::getInstance()->newRemoveMemberUseCase()->remove(
			$member->getId(),
			$this->getTestSysop()->getUser()->getId()
		);

		DeferredUpdates::doUpdates();
	}

	private function deactivate( User $member ): void {
		MemberAccessExtension::getInstance()->newDeactivateMemberUseCase()->deactivate(
			$member->getId(),
			$this->getTestSysop()->getUser()->getId()
		);

		DeferredUpdates::doUpdates();
	}

}
