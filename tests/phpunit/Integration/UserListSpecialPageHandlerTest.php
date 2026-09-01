<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\Request\FauxRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;
use PermissionsError;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\UserListSpecialPageHandler
 */
class UserListSpecialPageHandlerTest extends ApiTestCase {

	public function testMemberCannotOpenTheUserList(): void {
		$this->expectException( PermissionsError::class );

		$this->openAsMember( 'Listusers' );
	}

	public function testMemberCannotOpenTheActiveUserList(): void {
		$this->expectException( PermissionsError::class );

		$this->openAsMember( 'Activeusers' );
	}

	public function testMemberCannotOpenTheBlockList(): void {
		$this->expectException( PermissionsError::class );

		$this->openAsMember( 'BlockList' );
	}

	/**
	 * Special:Redirect answers a numeric user ID with a redirect to that account's user page, so
	 * walking IDs names every member without a listing page.
	 */
	public function testMemberCannotResolveAnAccountThroughRedirect(): void {
		$this->expectException( PermissionsError::class );

		$this->openAsMember( 'Redirect', 'user/' . $this->newMember()->getId() );
	}

	/**
	 * Special:UserRights answers a numeric user ID with that account's name and its groups, before
	 * it asks whether the reader may change anything, so walking IDs names every member there too.
	 */
	public function testMemberCannotResolveAnAccountThroughUserRights(): void {
		$this->expectException( PermissionsError::class );

		$this->openAsMember( 'Userrights', '#' . $this->newMember()->getId() );
	}

	/**
	 * Its form resolves an account without a subpage to match on, so the page is closed whole.
	 */
	public function testMemberCannotOpenTheRedirectFormItself(): void {
		$this->expectException( PermissionsError::class );

		$this->openAsMember( 'Redirect' );
	}

	public function testTheRefusalNamesTheMemberMessage(): void {
		$this->assertSame(
			'memberaccess-special-page-denied',
			$this->refusalFor( 'Listusers' )->getMessageObject()->getKey()
		);
	}

	public function testAccountsOutsideTheReaderGroupKeepTheUserList(): void {
		$member = $this->newMember();

		$html = $this->open( 'Listusers', $this->getTestUser()->getUser() );

		$this->assertStringContainsString( $member->getName(), $html );
	}

	public function testMemberKeepsSpecialPagesThatNameNoAccount(): void {
		$this->assertStringContainsString( '(intentionallyblankpage)', $this->openAsMember( 'Blankpage' ) );
	}

	public function testTheBlockedPagesAreConfigurable(): void {
		$this->blockAmongOthers( 'Blankpage' );

		$this->expectException( PermissionsError::class );

		$this->openAsMember( 'Blankpage' );
	}

	public function testPageLeftOutOfTheConfiguredListStaysOpen(): void {
		$this->blockAmongOthers( 'Blankpage' );
		$member = $this->newMember();

		$this->assertStringContainsString( $member->getName(), $this->open( 'Listusers', $member ) );
	}

	public function testTranscludedUserListNamesNoAccountToAMember(): void {
		$member = $this->newMember();

		$html = $this->parseAs( '{{Special:Listusers}}', $member );

		$this->assertStringNotContainsString( $member->getName(), $html );
	}

	/**
	 * The refusal is not held to the reader group, since a page's rendered text is also produced by
	 * parses nobody asked for: secondary data updates and search index builds parse as an anonymous
	 * user, and a check on the group would let the roster through into those.
	 */
	public function testTranscludedUserListNamesNoAccountToAnAdmin(): void {
		$member = $this->newMember();

		$html = $this->parseAs( '{{Special:Listusers}}', $this->getTestSysop()->getAuthority() );

		$this->assertStringNotContainsString( $member->getName(), $html );
	}

	/**
	 * Refusing the inclusion by exception would end the parse of the whole page holding it, so it
	 * renders empty instead.
	 */
	public function testAPageTranscludingTheUserListRendersWithoutIt(): void {
		$member = $this->newMember();

		$html = $this->parseAs( 'before {{Special:Listusers}} after', $member );

		$this->assertStringContainsString( 'before', $html );
		$this->assertStringContainsString( 'after', $html );
		$this->assertStringNotContainsString( $member->getName(), $html );
	}

	public function testTranscludingAPageLeftOutOfTheConfiguredListStillWorks(): void {
		$this->blockAmongOthers( 'Blankpage' );
		$member = $this->newMember();

		$html = $this->parseAs( '{{Special:Listusers}}', $member );

		$this->assertStringContainsString( $member->getName(), $html );
	}

	/**
	 * Neighbours on either side, so that reading only the first or the last name of the list fails.
	 */
	private function blockAmongOthers( string $page ): void {
		$this->overrideConfigValue( 'MemberAccessBlockedSpecialPages', [ 'Allmessages', $page, 'Random' ] );
	}

	private function newMember(): User {
		return $this->getMutableTestUser( [ 'reader' ] )->getUser();
	}

	private function refusalFor( string $page ): PermissionsError {
		try {
			$this->openAsMember( $page );
		} catch ( PermissionsError $refusal ) {
			return $refusal;
		}

		$this->fail( "Special:$page was not refused" );
	}

	private function openAsMember( string $page, string|false $subPage = false ): string {
		return $this->open( $page, $this->newMember(), $subPage );
	}

	/**
	 * Special pages are opened through the factory rather than executed directly, since the hook
	 * this tests is fired by SpecialPage::run() rather than by the page itself. The title is the one
	 * a link would carry, since the factory answers any other spelling with a redirect to it.
	 */
	private function open( string $page, Authority $performer, string|false $subPage = false ): string {
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setAuthority( $performer );
		$context->setRequest( new FauxRequest() );
		$context->setLanguage( 'qqx' );

		$output = new OutputPage( $context );
		$context->setOutput( $output );

		$this->getServiceContainer()->getSpecialPageFactory()->executePath(
			SpecialPage::getTitleFor( $page, $subPage ),
			$context
		);

		return $output->getHTML();
	}

	/**
	 * The parse action is the transclusion path a member can ask for directly, whatever the wiki
	 * has on its pages.
	 */
	private function parseAs( string $wikitext, Authority $performer ): string {
		[ $result ] = $this->doApiRequest( [
			'action' => 'parse',
			'text' => $wikitext,
			'contentmodel' => 'wikitext',
			'prop' => 'text',
			'formatversion' => '2'
		], null, false, $performer );

		return $result['parse']['text'];
	}

}
