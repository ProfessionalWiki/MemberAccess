<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\CodeLoginMode;
use ProfessionalWiki\MemberAccess\Application\MemberGroup;

/**
 * Whom each route admits, given whether an allowlist entry matched the address.
 *
 * @covers \ProfessionalWiki\MemberAccess\Application\CodeLoginMode
 */
class CodeLoginModeTest extends TestCase {

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
