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

	/**
	 * The feed names the account an ID belongs to before it names a single contribution, so an
	 * account that never edited resolves too.
	 */
	public function testMemberCannotResolveAnAccountThroughTheContributionsFeed(): void {
		$member = $this->newMember();

		$this->expectApiErrorCode( 'memberaccess-module-denied' );

		$this->requestAs( $member, [ 'action' => 'feedcontributions', 'user' => '#' . $member->getId() ] );
	}

	public function testMemberCannotAskWhetherAnAccountExists(): void {
		$member = $this->newMember();

		$this->expectApiErrorCode( 'memberaccess-module-denied' );

		$this->requestAs( $member, [
			'action' => 'validatepassword',
			'password' => 'a passphrase nobody uses',
			'user' => $member->getName()
		] );
	}

	public function testMemberCannotProbeAnAccountThroughEmail(): void {
		$member = $this->newMember();

		$this->expectApiErrorCode( 'memberaccess-module-denied' );

		$this->requestWithTokenAs( $member, [
			'action' => 'emailuser',
			'target' => $member->getName(),
			'subject' => 'Hello',
			'text' => 'Anyone there?'
		] );
	}

	public function testMemberCannotResolveAnAccountThroughUserRights(): void {
		$member = $this->newMember();

		$this->expectApiErrorCode( 'memberaccess-module-denied' );

		$this->requestWithTokenAs( $member, [ 'action' => 'userrights', 'user' => '#' . $member->getId() ] );
	}

	public function testMemberKeepsTheRestOfTheQueryApi(): void {
		$result = $this->queryAs( $this->newMember(), [ 'meta' => 'siteinfo' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
	}

	public function testAccountsOutsideTheReaderGroupCanStillListEveryAccount(): void {
		$result = $this->queryAs( $this->getTestUser()->getUser(), [ 'list' => 'allusers' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
	}

	public function testAccountsOutsideTheReaderGroupKeepPasswordValidation(): void {
		$result = $this->requestAs(
			$this->getTestUser()->getUser(),
			[ 'action' => 'validatepassword', 'password' => 'a passphrase nobody uses' ]
		);

		$this->assertArrayHasKey( 'validity', $result[0]['validatepassword'] );
	}

	public function testTheBlockedModulesAreConfigurable(): void {
		$this->overrideConfigValue( 'MemberAccessBlockedApiModules', [ 'siteinfo' ] );

		$this->expectApiErrorCode( 'memberaccess-module-denied' );

		$this->queryAs( $this->newMember(), [ 'meta' => 'siteinfo' ] );
	}

	public function testTheBlockedActionsAreConfigurable(): void {
		$this->overrideConfigValue( 'MemberAccessBlockedApiModules', [ 'paraminfo' ] );

		$this->expectApiErrorCode( 'memberaccess-module-denied' );

		$this->requestAs( $this->newMember(), [ 'action' => 'paraminfo', 'modules' => 'query' ] );
	}

	private function newMember(): User {
		return $this->getMutableTestUser( [ 'reader' ] )->getUser();
	}

	/**
	 * @param array<string, string> $params
	 * @return array<int, mixed>
	 */
	private function queryAs( User $user, array $params ): array {
		return $this->requestAs( $user, [ 'action' => 'query' ] + $params );
	}

	/**
	 * @param array<string, string> $params
	 * @return array<int, mixed>
	 */
	private function requestAs( User $user, array $params ): array {
		return $this->doApiRequest( $params, null, false, $user );
	}

	/**
	 * @param array<string, string> $params
	 * @return array<int, mixed>
	 */
	private function requestWithTokenAs( User $user, array $params ): array {
		return $this->doApiRequestWithToken( $params, null, $user );
	}

}
