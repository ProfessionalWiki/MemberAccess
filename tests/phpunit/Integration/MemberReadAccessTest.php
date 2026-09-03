<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;

/**
 * A member's name identifies nobody, so the pages and modules that name accounts are open to them as
 * to any other reader, and so is the file lookup Special:FilePath redirects through.
 *
 * @group Database
 * @coversNothing
 */
class MemberReadAccessTest extends ApiTestCase {

	/**
	 * @dataProvider accountNamingPageProvider
	 */
	public function testMemberCanOpenThePagesThatNameAccounts(
		string $page,
		string|false $subPage,
		string $expected
	): void {
		$member = $this->newMember();

		$html = $this->open(
			$page,
			$member,
			$subPage === false ? false : $this->fillIn( $subPage, $member )
		);

		$this->assertStringContainsString( $this->fillIn( $expected, $member ), $html );
	}

	/**
	 * The file lookup is here because Special:FilePath answers a file name with the file's URL by
	 * redirecting through it, so a member who cannot open it cannot follow a file path either.
	 *
	 * @return iterable<string, array{string, string|false, string}>
	 */
	public static function accountNamingPageProvider(): iterable {
		yield 'the account list' => [ 'Listusers', false, '{name}' ];
		yield 'the active account list' => [ 'Activeusers', false, '(activeusers-noresult)' ];
		yield 'the block list' => [ 'BlockList', false, '(ipblocklist-empty)' ];
		yield 'an account looked up by id' => [ 'Userrights', '#{id}', '{name}' ];
		yield 'the user page of an account looked up by id' => [ 'Redirect', 'user/{id}', '{userpage}' ];
		yield 'a file looked up by name' => [ 'Redirect', 'file/Example.png', '(redirect-not-exists)' ];
	}

	/**
	 * A transclusion renders for whoever asks and outlives the request that asked, so a refusal here
	 * emptied the list for everyone, members and staff alike.
	 */
	public function testTranscludedAccountListNamesAnAccountToAMember(): void {
		$member = $this->newMember();

		$this->assertStringContainsString(
			$member->getName(),
			$this->parseAs( '{{Special:Listusers}}', $member )
		);
	}

	/**
	 * @dataProvider accountListingModuleProvider
	 * @param array<string, string> $params
	 */
	public function testMemberCanUseTheApiModulesThatNameAccounts( array $params, string $resultKey ): void {
		$this->assertArrayHasKey( $resultKey, $this->queryAs( $params, $this->newMember() ) );
	}

	/**
	 * @return iterable<string, array{array<string, string>, string}>
	 */
	public static function accountListingModuleProvider(): iterable {
		yield 'allusers' => [ [ 'list' => 'allusers' ], 'allusers' ];
		yield 'users' => [ [ 'list' => 'users', 'ususers' => 'Someone' ], 'users' ];
		yield 'blocks' => [ [ 'list' => 'blocks' ], 'blocks' ];
	}

	public function testTheAccountListApiNamesTheMember(): void {
		$member = $this->newMember();

		$accounts = $this->queryAs( [ 'list' => 'allusers' ], $member )['allusers'];

		$this->assertContains( $member->getName(), array_column( $accounts, 'name' ) );
	}

	private function newMember(): User {
		return $this->getMutableTestUser( [ 'reader' ] )->getUser();
	}

	/**
	 * A data provider runs before there is an account to name, so it names the placeholders filled
	 * in here instead.
	 */
	private function fillIn( string $text, User $member ): string {
		return strtr( $text, [
			'{name}' => $member->getName(),
			'{id}' => (string)$member->getId(),
			// The spelling a URL carries, which is how the page names an account it redirects to.
			'{userpage}' => $member->getUserPage()->getDBkey()
		] );
	}

	/**
	 * Opened through the special page factory rather than executed directly, so that the page runs
	 * the way a request for it runs. The title is the one a link would carry, since the factory
	 * answers any other spelling with a redirect to it. A page that answers with a redirect renders
	 * no body, so its target is part of what is returned.
	 */
	private function open( string $page, User $performer, string|false $subPage = false ): string {
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

		return $output->getRedirect() . $output->getHTML();
	}

	/**
	 * The parse action is the transclusion path a member can ask for directly, whatever the wiki
	 * has on its pages.
	 */
	private function parseAs( string $wikitext, User $performer ): string {
		[ $result ] = $this->doApiRequest( [
			'action' => 'parse',
			'text' => $wikitext,
			'contentmodel' => 'wikitext',
			'prop' => 'text',
			'formatversion' => '2'
		], null, false, $performer );

		return $result['parse']['text'];
	}

	/**
	 * @param array<string, string> $params
	 * @return array<string, mixed>
	 */
	private function queryAs( array $params, User $performer ): array {
		[ $result ] = $this->doApiRequest( [ 'action' => 'query' ] + $params, null, false, $performer );

		return $result['query'];
	}

}
