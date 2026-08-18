<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\CodeLoginMode;
use ProfessionalWiki\MemberAccess\Application\MemberGroup;

/**
 * Which route a setting names, and whom that route admits given whether an allowlist entry matched
 * the address.
 *
 * @covers \ProfessionalWiki\MemberAccess\Application\CodeLoginMode
 */
class CodeLoginModeTest extends TestCase {

	/**
	 * @dataProvider settingProvider
	 */
	public function testSettingNamesTheRoute( string $setting, CodeLoginMode $expected ): void {
		$this->assertSame( $expected, CodeLoginMode::fromSetting( $setting ) );
	}

	/**
	 * A setting nobody recognises names no route: admitting anybody is an explicit decision, and a
	 * typo is not one.
	 */
	public static function settingProvider(): iterable {
		yield 'off' => [ 'off', CodeLoginMode::Off ];
		yield 'allowlisted' => [ 'allowlisted', CodeLoginMode::Allowlisted ];
		yield 'open' => [ 'open', CodeLoginMode::Open ];
		yield 'a value nobody recognises' => [ 'sometimes', CodeLoginMode::Off ];
		yield 'a near miss' => [ 'Allowlisted', CodeLoginMode::Off ];
		yield 'an empty setting' => [ '', CodeLoginMode::Off ];
	}

	public function testRouteThatIsOffRefusesAnAddressAnEntryMatches(): void {
		$this->assertFalse( CodeLoginMode::Off->admits( $this->aGroup() ) );
	}

	public function testRouteThatIsOffRefusesAnAddressNoEntryMatches(): void {
		$this->assertFalse( CodeLoginMode::Off->admits( null ) );
	}

	public function testAllowlistedRouteAdmitsAnAddressAnEntryMatches(): void {
		$this->assertTrue( CodeLoginMode::Allowlisted->admits( $this->aGroup() ) );
	}

	public function testAllowlistedRouteRefusesAnAddressNoEntryMatches(): void {
		$this->assertFalse( CodeLoginMode::Allowlisted->admits( null ) );
	}

	public function testOpenRouteAdmitsAnAddressAnEntryMatches(): void {
		$this->assertTrue( CodeLoginMode::Open->admits( $this->aGroup() ) );
	}

	public function testOpenRouteAdmitsAnAddressNoEntryMatches(): void {
		$this->assertTrue( CodeLoginMode::Open->admits( null ) );
	}

	private function aGroup(): MemberGroup {
		return new MemberGroup( id: 1, name: 'Acme', creationTimestamp: '20260101000000' );
	}

}
