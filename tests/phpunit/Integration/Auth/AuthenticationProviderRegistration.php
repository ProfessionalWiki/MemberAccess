<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use MediaWiki\MainConfigNames;

/**
 * The test setup replaces the wiki's AuthManager configuration with a fixed one, so the provider is
 * added back from the manifest, which also checks that the manifest can build it.
 */
trait AuthenticationProviderRegistration {

	/**
	 * The test setup lists core's password providers without the sort keys a wiki gives them, which
	 * would put ours last where a wiki puts it between them. Theirs get the keys the wiki gives them,
	 * so that the providers answer in the order they do on a wiki.
	 */
	private function registerOurAuthenticationProvider(): void {
		$config = $this->getConfVar( MainConfigNames::AuthManagerConfig );
		$config['primaryauth'] = array_map( $this->sortedAsTheWikiSortsIt( ... ), $config['primaryauth'] )
			+ $this->ourAuthenticationProviderConfig();

		$this->overrideConfigValue( MainConfigNames::AuthManagerConfig, $config );
	}

	/**
	 * @param array<string, mixed> $provider
	 * @return array<string, mixed>
	 */
	private function sortedAsTheWikiSortsIt( array $provider ): array {
		$onAWiki = $this->getConfVar( MainConfigNames::AuthManagerAutoConfig )['primaryauth'];
		$provider['sort'] = $onAWiki[$provider['class']]['sort'];

		return $provider;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function ourAuthenticationProviderConfig(): array {
		$manifest = json_decode( (string)file_get_contents( __DIR__ . '/../../../../extension.json' ), true );

		return $manifest['AuthManagerAutoConfig']['primaryauth'];
	}

}
