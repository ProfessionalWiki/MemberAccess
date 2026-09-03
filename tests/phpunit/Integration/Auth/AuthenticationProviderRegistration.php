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
		$config['primaryauth'] = $this->sortedAsAWikiSortsThem( $config['primaryauth'] )
			+ $this->ourAuthenticationProviderConfig();

		$this->overrideConfigValue( MainConfigNames::AuthManagerConfig, $config );
	}

	/**
	 * The test setup lists core's password providers without the sort keys a wiki gives them, which
	 * would put ours after the local password provider, where a wiki puts it before. Each gets the
	 * key it has on a wiki, so that the providers answer in the order they do there.
	 *
	 * @param array<int|string, array<string, mixed>> $providers
	 * @return array<int|string, array<string, mixed>>
	 */
	private function sortedAsAWikiSortsThem( array $providers ): array {
		$onAWiki = $this->getConfVar( MainConfigNames::AuthManagerAutoConfig )['primaryauth'];

		foreach ( $providers as &$provider ) {
			$provider['sort'] = $onAWiki[$provider['class']]['sort'] ?? 0;
		}

		return $providers;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function ourAuthenticationProviderConfig(): array {
		$manifest = json_decode( (string)file_get_contents( __DIR__ . '/../../../../extension.json' ), true );

		return $manifest['AuthManagerAutoConfig']['primaryauth'];
	}

}
