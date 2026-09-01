<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\CompletionProbeSpecialPage;

/**
 * @group Database
 * @group API
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\UserNameCompletionHandler
 */
class UserNameCompletionHandlerTest extends ApiTestCase {

	/**
	 * The completion is asked of the special page directly rather than through SpecialPage::run(),
	 * so closing the page leaves this way of naming accounts open.
	 */
	public function testMemberCompletingAUserSubpageIsNamedNoAccount(): void {
		$member = $this->newMember();

		$this->assertSame( [], $this->completeAs( $member, 'Special:Contributions/' . $member->getName() ) );
	}

	public function testAccountsOutsideTheReaderGroupKeepUsernameCompletion(): void {
		$member = $this->newMember();

		$this->assertSame(
			[ 'Special:Contributions/' . $member->getName() ],
			$this->completeAs( $this->getTestUser()->getUser(), 'Special:Contributions/' . $member->getName() )
		);
	}

	/**
	 * Any page that asks the wiki for a username search is reached, whether core or an extension
	 * declares it, which is what stands in here for the pages this file does not name.
	 */
	public function testMemberCompletingASubpageOfAnyPageThatNamesAccountsIsNamedNone(): void {
		$this->registerTheProbe( [
			'class' => CompletionProbeSpecialPage::class,
			'services' => [ 'UserNamePrefixSearch' ]
		] );
		$member = $this->newMember();

		$this->assertSame( [], $this->completeAs( $member, 'Special:CompletionProbe/' . $member->getName() ) );
	}

	public function testAccountsOutsideTheReaderGroupKeepCompletionOnSuchAPage(): void {
		$this->registerTheProbe( [
			'class' => CompletionProbeSpecialPage::class,
			'services' => [ 'UserNamePrefixSearch' ]
		] );
		$member = $this->newMember();

		$this->assertSame(
			[ 'Special:CompletionProbe/' . $member->getName() ],
			$this->completeAs( $this->getTestUser()->getUser(), 'Special:CompletionProbe/' . $member->getName() )
		);
	}

	/**
	 * A page may be built by a factory of its own rather than from its class, and may ask for the
	 * search as an optional service; both are handed it the same way.
	 */
	public function testMemberIsNamedNoAccountByAPageBuiltByItsOwnFactory(): void {
		$this->registerTheProbe( [
			'factory' => [ CompletionProbeSpecialPage::class, 'newFromUserNames' ],
			'optional_services' => [ 'UserNamePrefixSearch' ]
		] );
		$member = $this->newMember();

		$this->assertSame( [], $this->completeAs( $member, 'Special:CompletionProbe/' . $member->getName() ) );
	}

	public function testMemberKeepsCompletionOfSubpagesThatNameNoAccount(): void {
		$this->insertPage( 'Member access completion probe' );

		$this->assertSame(
			[ 'Special:AllPages/Member access completion probe' ],
			$this->completeAs( $this->newMember(), 'Special:AllPages/Member access completion pro' )
		);
	}

	/**
	 * What the entry says it builds stays in it, so the page is still built from its own class and
	 * still asserted to be it, and whatever else the entry carries survives too.
	 */
	public function testTheRewrittenEntryKeepsEverythingItSaid(): void {
		$entry = [
			'class' => CompletionProbeSpecialPage::class,
			'services' => [ 'UserNamePrefixSearch' ],
			'styles' => [ 'some.styles.module' ]
		];
		$list = [ 'CompletionProbe' => $entry ];

		MemberAccessExtension::newUserNameCompletionHookHandler()->onSpecialPage_initList( $list );

		$this->assertSame( $entry, array_diff_key( $list['CompletionProbe'], [ 'factory' => null ] ) );
	}

	public function testAnEntryThatAsksForNoUserNameSearchIsLeftAlone(): void {
		$entry = [ 'class' => CompletionProbeSpecialPage::class, 'services' => [ 'UserNameUtils' ] ];
		$list = [ 'CompletionProbe' => $entry ];

		MemberAccessExtension::newUserNameCompletionHookHandler()->onSpecialPage_initList( $list );

		$this->assertSame( $entry, $list['CompletionProbe'] );
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private function registerTheProbe( array $spec ): void {
		$this->overrideConfigValue( MainConfigNames::SpecialPages, [ 'CompletionProbe' => $spec ] );
	}

	/**
	 * The reader group is one of the member's groups rather than their only one, so that a check
	 * for it cannot pass by looking at a single group.
	 */
	private function newMember(): User {
		return $this->getMutableTestUser( [ 'alumni', 'reader', 'volunteer' ] )->getUser();
	}

	/**
	 * The action the search box asks for as its suggestions are typed.
	 *
	 * @return string[] The titles offered
	 */
	private function completeAs( User $user, string $search ): array {
		[ $result ] = $this->doApiRequest(
			[ 'action' => 'opensearch', 'search' => $search, 'limit' => '10' ],
			null,
			false,
			$user
		);

		return $result[1];
	}

}
