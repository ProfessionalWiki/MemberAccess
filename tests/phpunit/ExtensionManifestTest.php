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
	 * A wiki that loaded the extension and set nothing admits nobody: every route is an explicit
	 * setting, so that loading alone does nothing an administrator did not ask for.
	 */
	public function testTheCodeRouteIsNotOfferedByDefault(): void {
		$this->assertSame( 'off', $this->configDefault( 'MemberAccessCodeLogin' ) );
	}

	public function testSingleSignOnIsLeftAloneByDefault(): void {
		$this->assertFalse( $this->configDefault( 'MemberAccessApplyAllowlistToSso' ) );
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

	/**
	 * A route names its handler as text, so a factory that was renamed or never written answers the
	 * first request with a fatal rather than the endpoint.
	 *
	 * @dataProvider restRouteProvider
	 */
	public function testRouteNamesAFactoryThatExists( string $factory ): void {
		$this->assertIsCallable( $factory );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function restRouteProvider(): iterable {
		foreach ( self::manifest()['RestRoutes'] as $route ) {
			yield implode( '|', $route['method'] ) . ' ' . $route['path'] => [ $route['factory'] ];
		}
	}

	private function configDefault( string $name ): mixed {
		return self::manifest()['config'][$name]['value'];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function manifest(): array {
		return json_decode( (string)file_get_contents( __DIR__ . '/../../extension.json' ), true );
	}

}
