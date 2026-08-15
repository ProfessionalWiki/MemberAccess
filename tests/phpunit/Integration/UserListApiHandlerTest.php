<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;

/**
 * @group Database
 * @group API
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\UserListApiHandler
 */
class UserListApiHandlerTest extends ApiTestCase {

	public function testMemberCannotListEveryAccount(): void {
		$this->expectApiErrorCode( 'memberaccess-module-denied' );

		$this->queryAs( $this->newMember(), [ 'list' => 'allusers' ] );
	}

	public function testMemberCannotLookUpAccounts(): void {
		$this->expectApiErrorCode( 'memberaccess-module-denied' );

		$this->queryAs( $this->newMember(), [ 'list' => 'users', 'ususers' => 'Someone' ] );
	}

	public function testMemberCannotListBlockedAccounts(): void {
		$this->expectApiErrorCode( 'memberaccess-module-denied' );

		$this->queryAs( $this->newMember(), [ 'list' => 'blocks' ] );
	}

	/**
	 * No module that lists accounts is generator capable, since accounts are not pages, so this
	 * uses a module that is. The point is that asking through a generator is refused as well.
	 */
	public function testBlockedModuleUsedAsAGeneratorIsRefused(): void {
		$this->overrideConfigValue( 'MemberAccessBlockedApiModules', [ 'allpages' ] );

		$this->expectApiErrorCode( 'memberaccess-module-denied' );

		$this->queryAs( $this->newMember(), [ 'generator' => 'allpages' ] );
	}

	public function testAskingForOneBlockedModuleAmongOthersIsRefused(): void {
		$this->expectApiErrorCode( 'memberaccess-module-denied' );

		$this->queryAs( $this->newMember(), [ 'list' => 'logevents|allusers' ] );
	}

	public function testMemberKeepsTheRestOfTheQueryApi(): void {
		$result = $this->queryAs( $this->newMember(), [ 'meta' => 'siteinfo' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
	}

	public function testAccountsOutsideTheReaderGroupCanStillListEveryAccount(): void {
		$result = $this->queryAs( $this->getTestUser()->getUser(), [ 'list' => 'allusers' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
	}

	public function testTheBlockedModulesAreConfigurable(): void {
		$this->overrideConfigValue( 'MemberAccessBlockedApiModules', [ 'siteinfo' ] );

		$this->expectApiErrorCode( 'memberaccess-module-denied' );

		$this->queryAs( $this->newMember(), [ 'meta' => 'siteinfo' ] );
	}

	private function newMember(): User {
		return $this->getMutableTestUser( [ 'reader' ] )->getUser();
	}

	/**
	 * @param array<string, string> $params
	 * @return array<int, mixed>
	 */
	private function queryAs( User $user, array $params ): array {
		return $this->doApiRequest( [ 'action' => 'query' ] + $params, null, false, $user );
	}

}
