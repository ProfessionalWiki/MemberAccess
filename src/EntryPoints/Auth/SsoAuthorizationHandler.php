<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\Auth;

use MediaWiki\Auth\AuthManager;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use ProfessionalWiki\MemberAccess\Application\AllowlistMatcher;
use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\OpaqueUsername;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use Psr\Log\LoggerInterface;

/**
 * Holds single sign-on logins to the same allowlist the one-time codes are held to, so connecting
 * an identity provider admits nobody by itself. A wiki that wants its identity provider to decide
 * on its own turns this off, and the route is then left entirely alone.
 *
 * Handles PluggableAuth's PluggableAuthUserAuthorization hook. It ships with the extension and lies
 * dormant while no provider is configured, so the rule holds from the first connection onwards.
 * The interface is deliberately not implemented, to keep PluggableAuth an optional companion.
 *
 * Accounts that are no members are exempt: staff who prefer to sign in through the identity
 * provider were never admitted by the allowlist and are not meant to be on the member list. Every
 * such login of an address the allowlist would not admit is recorded.
 *
 * A login that has no account yet is admitted only under a name that identifies nobody, since the
 * name it is created under is settled before this runs and is what every account listing shows.
 *
 * The exemption does not extend to an account that carries the reader group without a roster row.
 * The group is the mark of an account the allowlist created — provisioning adds it before the
 * roster row, and a removal leaves it on the account it closes — so such an account is one the
 * roster forgot: admitting it as staff would put it outside the allowlist for good, on a route
 * whose identity provider can keep handing logins back to it.
 */
class SsoAuthorizationHandler {

	public function __construct(
		private readonly bool $allowlistApplies,
		private readonly AllowlistMatcher $matcher,
		private readonly MemberRepository $members,
		private readonly UserGroupManager $userGroups,
		private readonly AuthManager $authManager,
		private readonly LoggerInterface $logger,
		/**
		 * The address OpenIDConnect resolved for this login, which is what the extension had to go
		 * on while the account was being named.
		 * {@see SsoUsernameProcessor}
		 */
		private readonly ?string $resolvedAddress,
		private readonly string $readerGroup
	) {
	}

	/**
	 * Refusing returns false, which stops the hook: a handler after this one cannot hand the login
	 * back. Nothing else this returns matters to PluggableAuth, which reads only $authorized.
	 */
	public function onPluggableAuthUserAuthorization( UserIdentity $user, bool &$authorized ): bool {
		// A wiki can keep the allowlist off this route, and single sign-on is then somebody else's
		// business entirely: nobody to refuse, and nobody to make a member.
		if ( !$this->allowlistApplies || !$authorized ) {
			return true;
		}

		$member = $user->isRegistered() ? $this->members->getMember( $user->getId(), ReadConsistency::UpToDate ) : null;

		if ( $user->isRegistered() && $member === null ) {
			return $this->authorizeAccountThatIsNoMember( $user, $authorized );
		}

		// The address the roster recorded is the one the allowlist admitted, so it is what removing
		// an entry has to end access for, whatever address the account carries now. A login without
		// an account yet has only the address the identity provider vouched for.
		$address = $member === null
			? $this->addressOf( $user )
			: NormalizedEmail::fromString( $member->email );

		$group = $address === null ? null : $this->matcher->match( $address );

		if ( $address === null || $group === null ) {
			$authorized = false;
			$this->logger->info( 'Single sign-on login refused: the address is not admitted', [
				'email' => $address?->hash()
			] );

			return false;
		}

		if ( !$user->isRegistered() ) {
			return $this->admitANewAccount( $user, $address, $group, $authorized );
		}

		// A member the open code login route admitted has no group. The entry that matches them
		// now says which group does, and this login is where that is written down. The repository
		// is what holds a group already given in place; asking here keeps most logins from writing.
		if ( $member !== null && $member->groupId === null ) {
			$this->members->attributeToGroup( userId: $user->getId(), groupId: $group->id );
		}

		return true;
	}

	/**
	 * The account is created by the plugin that authenticated the login, under a name settled on
	 * before this runs, so all that is left here is to refuse a name that would identify the member
	 * holding it. OpenIDConnect asks the extension for that name and is given an opaque one;
	 * a plugin that offers no such say leaves its own name, which is refused rather than admitted
	 * and then lived with, since a name is what everything that lists accounts gives away.
	 *
	 * {@see SsoUsernameProcessor}
	 */
	private function admitANewAccount(
		UserIdentity $user,
		NormalizedEmail $address,
		MemberGroup $group,
		bool &$authorized
	): bool {
		if ( !OpaqueUsername::isOpaque( $user->getName() ) ) {
			$authorized = false;
			$this->logger->warning( $this->whyTheNameIsNotTheExtensionsOwn(), [ 'email' => $address->hash() ] );

			return false;
		}

		$this->markForProvisioning( $user, $address, $group );

		return true;
	}

	/**
	 * Two things leave the account under a name of the plugin's, and they are put right in different
	 * places: a plugin that never handed the extension the address could not have known the login
	 * was a member's, while one that did and named the account anyway is ignoring what it was given.
	 */
	private function whyTheNameIsNotTheExtensionsOwn(): string {
		if ( $this->resolvedAddress === null ) {
			return 'Single sign-on login refused: the extension was given no address while the account was being named';
		}

		return 'Single sign-on login refused: the account would be created under a name that identifies its holder';
	}

	/**
	 * The reader group is what tells a staff account from one the roster forgot: it is the mark of
	 * an account the allowlist created, so carrying it without a roster row means the roster forgot
	 * the account rather than never knew it.
	 */
	private function authorizeAccountThatIsNoMember( UserIdentity $user, bool &$authorized ): bool {
		if ( $this->holdsTheReaderGroup( $user ) ) {
			return $this->refuseAccountTheRosterForgot( $user, $authorized );
		}

		return $this->admitAccountThatIsNoMember( $user );
	}

	private function holdsTheReaderGroup( UserIdentity $user ): bool {
		return in_array( $this->readerGroup, $this->userGroups->getUserGroups( $user ), true );
	}

	private function refuseAccountTheRosterForgot( UserIdentity $user, bool &$authorized ): bool {
		$authorized = false;
		$this->logger->info( 'Single sign-on login refused: the account was provisioned through the allowlist but is no longer on the roster', [
			'user' => $user->getId()
		] );

		return false;
	}

	private function admitAccountThatIsNoMember( UserIdentity $user ): bool {
		$address = $this->addressOf( $user );

		if ( $address === null || $this->matcher->match( $address ) === null ) {
			$this->logger->info( 'Single sign-on admitted an account that is no member, on an address the allowlist does not admit', [
				'user' => $user->getId(),
				'email' => $address?->hash()
			] );
		}

		return true;
	}

	/**
	 * On an account PluggableAuth is about to create, this is the address the identity provider
	 * vouched for. An existing account carries its stored address instead: PluggableAuth writes the
	 * provider's address only after authorization. Members are therefore matched on the roster's
	 * address, and this one is only read where the stored address is the wanted one.
	 */
	private function addressOf( UserIdentity $user ): ?NormalizedEmail {
		return $user instanceof User ? NormalizedEmail::fromString( $user->getEmail() ) : null;
	}

	/**
	 * The account is created by the provider that authenticated it, which is why provisioning is
	 * left for our own provider to do once it exists. It is created under the name that provider
	 * has settled on, so that name is what the account will be recognised by, rather than the
	 * address.
	 */
	private function markForProvisioning( UserIdentity $user, NormalizedEmail $address, MemberGroup $group ): void {
		$this->authManager->setAuthenticationSessionData(
			MemberAuthenticationProvider::PROVISIONING_SESSION_KEY,
			( new PendingProvisioning(
				username: $user->getName(),
				email: $address,
				groupId: $group->id
			) )->toSessionData()
		);
	}

}
