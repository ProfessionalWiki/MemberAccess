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

	public function testMemberCanOpenTheUserList(): void {
		$member = $this->newMember();

		$this->assertStringContainsString( $member->getName(), $this->open( 'Listusers', $member ) );
	}

	/**
	 * Special:FilePath answers a file name with the file's URL by redirecting through this page, so
	 * a member who cannot open it cannot follow a file path either.
	 */
	public function testMemberCanOpenTheFileLookup(): void {
		$html = $this->open( 'Redirect', $this->newMember(), 'file/Example.png' );

		$this->assertStringContainsString( '(redirect-not-exists)', $html );
	}

	/**
	 * @dataProvider accountListingModuleProvider
	 * @param array<string, string> $params
	 */
	public function testMemberCanUseTheApiModulesThatNameAccounts( array $params ): void {
		[ $result ] = $this->doApiRequest(
			[ 'action' => 'query' ] + $params,
			null,
			false,
			$this->newMember()
		);

		$this->assertArrayHasKey( 'query', $result );
	}

	/**
	 * @return iterable<string, array{array<string, string>}>
	 */
	public static function accountListingModuleProvider(): iterable {
		yield 'allusers' => [ [ 'list' => 'allusers' ] ];
		yield 'users' => [ [ 'list' => 'users', 'ususers' => 'Someone' ] ];
		yield 'blocks' => [ [ 'list' => 'blocks' ] ];
	}

	private function newMember(): User {
		return $this->getMutableTestUser( [ 'reader' ] )->getUser();
	}

	/**
	 * Opened through the special page factory rather than executed directly, so that the page runs
	 * the way a request for it runs. The title is the one a link would carry, since the factory
	 * answers any other spelling with a redirect to it.
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

		return $output->getHTML();
	}

}
