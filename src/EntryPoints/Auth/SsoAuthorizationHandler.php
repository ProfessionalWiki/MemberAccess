<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\Auth;

use MediaWiki\Auth\AuthManager;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use ProfessionalWiki\MemberAccess\Application\AllowlistMatcher;
use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
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
 */
class SsoAuthorizationHandler {

	public function __construct(
		private readonly bool $allowlistApplies,
		private readonly AllowlistMatcher $matcher,
		private readonly MemberRepository $members,
		private readonly AuthManager $authManager,
		private readonly LoggerInterface $logger
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
			return $this->admitAccountThatIsNoMember( $user );
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
			$this->markForProvisioning( $user, $address, $group );

			return true;
		}

		// A member the open code login route admitted has no group. The entry that matches them
		// now says which group does, and this login is where that is written down.
		if ( $member !== null && $member->groupId === null ) {
			$this->members->attributeToGroup( userId: $user->getId(), groupId: $group->id );
		}

		return true;
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
