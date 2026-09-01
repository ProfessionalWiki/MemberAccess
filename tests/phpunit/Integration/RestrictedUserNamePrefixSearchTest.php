<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserNamePrefixSearch;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\EntryPoints\RestrictedUserNamePrefixSearch;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyUserNamePrefixSearch;
use ReflectionClass;
use ReflectionMethod;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\RestrictedUserNamePrefixSearch
 */
class RestrictedUserNamePrefixSearchTest extends MediaWikiIntegrationTestCase {

	/**
	 * The search this wraps is asked rather than the one it inherits, so that a wiki that wired its
	 * own keeps it, and it is asked exactly what the page asked for.
	 */
	public function testWhatThePageAsksForReachesTheSearchThatIsWrapped(): void {
		$inner = new SpyUserNamePrefixSearch( [ 'Someone else' ] );
		$audience = $this->getTestUser()->getAuthority();
		$search = $this->newRestrictedSearchAskedBy( $this->getTestUser()->getUser(), $inner );

		$this->assertSame( [ 'Someone else' ], $search->search( $audience, 'Some', 7, 3 ) );
		$this->assertSame( [ $audience, 'Some', 7, 3 ], $inner->asked );
	}

	/**
	 * The offset is what walks the whole account table, so it has to reach the search wrapped here.
	 */
	public function testTheSearchPagesWithTheOffsetItIsGiven(): void {
		$member = $this->newMember();
		$search = $this->newRestrictedSearchAskedBy( $this->getTestUser()->getUser() );

		$this->assertSame(
			[ $member->getName() ],
			$search->search( UserNamePrefixSearch::AUDIENCE_PUBLIC, $member->getName(), 10 )
		);
		$this->assertSame(
			[],
			$search->search( UserNamePrefixSearch::AUDIENCE_PUBLIC, $member->getName(), 10, 1 )
		);
	}

	/**
	 * The constructor of the class this wraps is not called, so a way to search left to that class
	 * fails when it is asked. This says so at build time instead.
	 */
	public function testNoWayToSearchIsLeftToTheClassThisWraps(): void {
		$this->assertSame(
			[],
			array_diff(
				$this->publicMethodsOf( UserNamePrefixSearch::class ),
				$this->publicMethodsOf( RestrictedUserNamePrefixSearch::class )
			)
		);
	}

	/**
	 * @return string[] The names the class declares itself, the constructor aside
	 */
	private function publicMethodsOf( string $class ): array {
		return array_column(
			array_filter(
				( new ReflectionClass( $class ) )->getMethods( ReflectionMethod::IS_PUBLIC ),
				static fn ( ReflectionMethod $method ): bool
					=> $method->class === $class && !$method->isConstructor()
			),
			'name'
		);
	}

	/**
	 * The reader group is one of the member's groups rather than their only one, so that a check
	 * for it cannot pass by looking at a single group.
	 */
	private function newMember(): User {
		return $this->getMutableTestUser( [ 'alumni', 'reader', 'volunteer' ] )->getUser();
	}

	private function newRestrictedSearchAskedBy(
		User $user,
		?UserNamePrefixSearch $inner = null
	): RestrictedUserNamePrefixSearch {
		$services = $this->getServiceContainer();

		return new RestrictedUserNamePrefixSearch(
			inner: $inner ?? $services->getUserNamePrefixSearch(),
			userGroups: $services->getUserGroupManager(),
			readerGroup: 'reader',
			requestingUser: static fn (): UserIdentity => $user
		);
	}

}
