<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\Auth;

use Closure;
use Exception;
use ProfessionalWiki\MemberAccess\Application\AllowlistMatcher;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\UsernameMinter;

/**
 * Names the account a single sign-on login is about to be given, where the allowlist admits the
 * address that login carries: a member's account is named after nobody, whatever the identity
 * provider would have called it.
 *
 * Handles OpenIDConnect's PreferredUsernameProcessor, which it asks only while creating an account,
 * and before making the name unique. A login the allowlist does not admit is no member's, so it
 * keeps the name it came with: staff signing in through the identity provider are named as they
 * always were.
 *
 * A processor the wiki configured itself runs first, for every login, and is then held to the same
 * rule: what it decides reaches every login but a member's.
 *
 * The address the allowlist is asked about is the one OpenIDConnect resolved. The extension is
 * handed it by the processor it wraps around that plugin's own say over the address, which the
 * plugin calls before this one, within the one authenticate() call. The claims are what is left
 * when nothing was recorded, and they hold the address only where the identity provider put it in
 * a token rather than behind its userinfo endpoint.
 */
class SsoUsernameProcessor {

	public function __construct(
		private readonly AllowlistMatcher $matcher,
		private readonly UsernameMinter $minter,
		private readonly ?Closure $wrapped,
		private readonly ?string $resolvedAddress
	) {
	}

	/**
	 * @param array<string, mixed> $attributes The claims the identity provider returned
	 */
	public function __invoke( ?string $preferredUsername, array $attributes ): ?string {
		$name = $this->wrapped === null
			? $preferredUsername
			: $this->asString( ( $this->wrapped )( $preferredUsername, $attributes ) );

		return $this->isAdmitted( $attributes ) ? $this->mintedName( $name ) : $name;
	}

	/**
	 * A name that cannot be minted leaves the plugin's, which the authorization gate then refuses:
	 * the login is turned away either way, and letting the failure out instead would put the
	 * exception on PluggableAuth's error screen for the visitor to read.
	 *
	 * {@see \ProfessionalWiki\MemberAccess\EntryPoints\Auth\SsoAuthorizationHandler}
	 */
	private function mintedName( ?string $fallback ): ?string {
		try {
			return $this->minter->mintUsername();
		} catch ( Exception ) {
			return $fallback;
		}
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	private function isAdmitted( array $attributes ): bool {
		$address = $this->resolvedAddress ?? $this->asString( $attributes['email'] ?? null );
		$email = $address === null ? null : NormalizedEmail::fromString( $address );

		return $email !== null && $this->matcher->match( $email ) !== null;
	}

	private function asString( mixed $value ): ?string {
		return is_string( $value ) ? $value : null;
	}

}
