<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests;

use PHPUnit\Framework\TestCase;

class ExtensionManifestTest extends TestCase {

	public function testReaderGroupDefaultsToReader(): void {
		$this->assertSame( 'reader', $this->configDefault( 'MemberAccessReaderGroup' ) );
	}

	public function testCodeStaysValidForTenMinutes(): void {
		$this->assertSame( 600, $this->configDefault( 'MemberAccessCodeTtlSeconds' ) );
	}

	public function testSenderAddressIsUnsetByDefault(): void {
		$this->assertNull( $this->configDefault( 'MemberAccessSenderAddress' ) );
	}

	/**
	 * The admin panel gives its section to everyone with panel access, which includes bureaucrats
	 * who are not sysops, so both groups need the right or the section errors on every call.
	 */
	public function testManagementRightIsGrantedToSysopsAndBureaucrats(): void {
		$permissions = $this->manifest()['GroupPermissions'];

		$this->assertTrue( $permissions['sysop']['memberaccess-manage'] );
		$this->assertTrue( $permissions['bureaucrat']['memberaccess-manage'] );
	}

	private function configDefault( string $name ): mixed {
		return $this->manifest()['config'][$name]['value'];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function manifest(): array {
		return json_decode( (string)file_get_contents( __DIR__ . '/../../extension.json' ), true );
	}

}
