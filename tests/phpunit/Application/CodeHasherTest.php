<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\CodeHasher;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\CodeHasher
 */
class CodeHasherTest extends TestCase {

	public function testHashDoesNotContainTheCode(): void {
		$this->assertStringNotContainsString( '12345678', $this->newHasher()->hash( '12345678' ) );
	}

	public function testHashIsTheSameForTheSameCode(): void {
		$hasher = $this->newHasher();

		$this->assertSame( $hasher->hash( '12345678' ), $hasher->hash( '12345678' ) );
	}

	public function testHashDiffersBetweenCodes(): void {
		$hasher = $this->newHasher();

		$this->assertNotSame( $hasher->hash( '12345678' ), $hasher->hash( '12345679' ) );
	}

	public function testHashDiffersBetweenSecrets(): void {
		$this->assertNotSame(
			( new CodeHasher( secret: 'first-secret' ) )->hash( '12345678' ),
			( new CodeHasher( secret: 'second-secret' ) )->hash( '12345678' )
		);
	}

	public function testMatchingCodeIsAccepted(): void {
		$hasher = $this->newHasher();

		$this->assertTrue( $hasher->matches( '12345678', $hasher->hash( '12345678' ) ) );
	}

	public function testDifferentCodeIsRejected(): void {
		$hasher = $this->newHasher();

		$this->assertFalse( $hasher->matches( '87654321', $hasher->hash( '12345678' ) ) );
	}

	public function testCodeIsRejectedAgainstAHashFromAnotherSecret(): void {
		$this->assertFalse(
			( new CodeHasher( secret: 'first-secret' ) )
				->matches( '12345678', ( new CodeHasher( secret: 'second-secret' ) )->hash( '12345678' ) )
		);
	}

	private function newHasher(): CodeHasher {
		return new CodeHasher( secret: 'test-secret' );
	}

}
