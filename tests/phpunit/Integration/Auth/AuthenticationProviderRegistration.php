<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use MediaWiki\MainConfigNames;

/**
 * The test setup replaces the wiki's AuthManager configuration with a fixed one, so the provider is
 * added back from the manifest, which also checks that the manifest can build it.
 */
trait AuthenticationProviderRegistration {

	private function registerOurAuthenticationProvider(): void {
		$config = $this->getConfVar( MainConfigNames::AuthManagerConfig );
		$config['primaryauth'] += $this->ourAuthenticationProviderConfig();

		$this->overrideConfigValue( MainConfigNames::AuthManagerConfig, $config );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function ourAuthenticationProviderConfig(): array {
		$manifest = json_decode( (string)file_get_contents( __DIR__ . '/../../../../extension.json' ), true );

		return $manifest['AuthManagerAutoConfig']['primaryauth'];
	}

}
