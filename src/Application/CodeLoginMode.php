<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * Whom the one-time code route lets in, or that the route is not offered at all.
 */
enum CodeLoginMode: string {

	/** No button on the login form, and no code is ever issued. */
	case Off = 'off';

	/** Only addresses an allowlist entry matches get in, and the entry's group admits them. */
	case Allowlisted = 'allowlisted';

	/** Every address gets in. An entry that matches still says which group admits them. */
	case Open = 'open';

	/**
	 * A setting nobody recognises leaves the route working, and working the way the extension
	 * exists to work.
	 */
	public static function fromSetting( string $setting ): self {
		return self::tryFrom( $setting ) ?? self::Allowlisted;
	}

	public function admits( ?MemberGroup $group ): bool {
		return match ( $this ) {
			self::Off => false,
			self::Allowlisted => $group !== null,
			self::Open => true
		};
	}

}
