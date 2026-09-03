<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Authority;
use MediaWiki\RenameUser\RenameuserSQL;
use MediaWiki\Tests\Api\ApiTestCase;
use PermissionsError;
use SpecialPageExecutor;

/**
 * Each way of reading the rename log, which is closed to everyone who cannot manage members.
 *
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\RegistrationHandler
 */
class RenameLogVisibilityTest extends ApiTestCase {

	public function testRenameLogNamesTheRenamedAccountToAnAdmin(): void {
		$renamed = $this->renameAnAccount();

		$entries = $this->logEventsFor( $this->admin(), 'renameuser' );

		$this->assertSame( [ 'User:' . $renamed ], array_column( $entries, 'title' ) );
	}

	public function testRenameLogIsClosedToAMember(): void {
		$this->renameAnAccount();

		$this->assertSame( [], $this->logEventsFor( $this->member(), 'renameuser' ) );
	}

	/**
	 * Log entries of a restricted type are kept out of recent changes when they are written, so
	 * there is nothing there for the query to filter.
	 */
	public function testRenamingAnAccountDoesNotShowUpInRecentChanges(): void {
		$this->editPage( 'Visible page', 'Some text' );
		$this->renameAnAccount();

		$changes = $this->recentChangesFor( $this->member() );

		$this->assertNotSame( [], $changes );
		$this->assertNotContains( 'renameuser', array_column( $changes, 'logtype' ) );
	}

	/**
	 * What keeps the entry out of recent changes, and its subject out of the log without a chosen
	 * type, is the restriction rather than anything else about a rename.
	 */
	public function testAnUnrestrictedRenameReachesRecentChangesAndTheLog(): void {
		$this->overrideConfigValue( MainConfigNames::LogRestrictions, [] );
		$renamed = $this->renameAnAccount();

		$changes = $this->recentChangesFor( $this->member() );

		$this->assertContains( 'renameuser', array_column( $changes, 'logtype' ) );
		$this->assertStringContainsString( $renamed, $this->specialLogFor( $this->member(), '' ) );
	}

	public function testSpecialLogNamesTheRenamedAccountToAnAdmin(): void {
		$renamed = $this->renameAnAccount();

		$html = $this->specialLogFor( $this->admin(), 'renameuser' );

		$this->assertStringContainsString( $renamed, $html );
	}

	public function testSpecialLogRefusesTheRenameLogToAMember(): void {
		$this->renameAnAccount();

		$this->expectException( PermissionsError::class );
		$this->specialLogFor( $this->member(), 'renameuser' );
	}

	public function testSpecialLogWithoutAChosenTypeNamesNoRenamedAccount(): void {
		$renamed = $this->renameAnAccount();

		$this->assertStringNotContainsString( $renamed, $this->specialLogFor( $this->member(), '' ) );
	}

	/**
	 * @return string The name the account was renamed away from
	 */
	private function renameAnAccount(): string {
		$account = $this->getMutableTestUser()->getUser();
		$oldName = $account->getName();

		$renamed = ( new RenameuserSQL(
			$oldName,
			'Member AB2345',
			$account->getId(),
			$this->getTestSysop()->getUser()
		) )->rename();

		$this->assertTrue( $renamed );
		DeferredUpdates::doUpdates();

		return $oldName;
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

}
