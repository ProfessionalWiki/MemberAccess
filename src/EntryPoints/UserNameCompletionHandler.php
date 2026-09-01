<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints;

use Closure;
use MediaWiki\SpecialPage\Hook\SpecialPage_initListHook;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserNamePrefixSearch;

/**
 * Keeps members from reading the wiki's list of accounts through the completion of a username.
 *
 * A page that completes a username takes the username search as a constructor dependency, and the
 * completion is asked of the page directly rather than through SpecialPage::run(), so closing such
 * a page leaves this way of naming accounts open. The page list is where that dependency is
 * replaced, for every page in the list when this hook runs that declares it, core and extension
 * alike, without naming any of them. A page another handler adds to the list afterwards is not
 * reached.
 *
 * The search itself is not replaced instead: the hook that wraps a service is not delivered to
 * extensions under every bootstrap this extension is loaded by, and a boundary cannot be left to a
 * hook that may not run.
 */
class UserNameCompletionHandler implements SpecialPage_initListHook {

	/**
	 * @param Closure(): UserIdentity $requestingUser
	 */
	public function __construct(
		private readonly UserGroupManager $userGroups,
		private readonly string $readerGroup,
		private readonly Closure $requestingUser
	) {
	}

	/**
	 * @param array<string, mixed> &$list
	 */
	public function onSpecialPage_initList( &$list ): void {
		foreach ( $list as $name => $spec ) {
			if ( is_array( $spec ) && $this->declaresTheUserNameSearch( $spec ) ) {
				$list[$name] = $this->specThatRestrictsTheSearch( $spec );
			}
		}
	}

	/**
	 * A page names its services rather than building them, which is what makes them replaceable
	 * here. A page that builds its own is not reached, and neither is one spelled as a bare class
	 * name or a callable, since neither declares anything.
	 *
	 * @param array<mixed, mixed> $spec
	 */
	private function declaresTheUserNameSearch( array $spec ): bool {
		$declared = array_merge(
			is_array( $spec['services'] ?? null ) ? $spec['services'] : [],
			is_array( $spec['optional_services'] ?? null ) ? $spec['optional_services'] : []
		);

		return in_array( 'UserNamePrefixSearch', $declared, true );
	}

	/**
	 * What the spec says the page is stays in it, so that the page is still built from its own
	 * services and still asserted to be what it claims. Arguments are replaced by what they are
	 * rather than by where they sit, since their order is the spec's business.
	 *
	 * @param array<mixed, mixed> $spec
	 * @return array<mixed, mixed>
	 */
	private function specThatRestrictsTheSearch( array $spec ): array {
		$construct = $this->constructorFor( $spec );

		if ( $construct === null ) {
			return $spec;
		}

		$spec['factory'] = fn ( mixed ...$arguments ): object
			=> $construct( ...array_map( $this->restricted( ... ), $arguments ) );

		return $spec;
	}

	/**
	 * @param array<mixed, mixed> $spec
	 * @return ?callable(mixed...): object
	 */
	private function constructorFor( array $spec ): ?callable {
		if ( isset( $spec['factory'] ) && is_callable( $spec['factory'] ) ) {
			return $spec['factory'];
		}

		$class = $spec['class'] ?? null;

		if ( !is_string( $class ) ) {
			return null;
		}

		return static fn ( mixed ...$arguments ): object => new $class( ...$arguments );
	}

	private function restricted( mixed $argument ): mixed {
		if ( !$argument instanceof UserNamePrefixSearch ) {
			return $argument;
		}

		return new RestrictedUserNamePrefixSearch(
			inner: $argument,
			userGroups: $this->userGroups,
			readerGroup: $this->readerGroup,
			requestingUser: $this->requestingUser
		);
	}

}
