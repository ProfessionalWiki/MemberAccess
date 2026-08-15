<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\NormalizedEmail
 */
class NormalizedEmailTest extends TestCase {

	public function testSurroundingWhitespaceIsRemoved(): void {
		$this->assertSame( 'jane@example.com', $this->normalize( "  jane@example.com\n" ) );
	}

	public function testUpperCaseIsFolded(): void {
		$this->assertSame( 'jane.doe@example.com', $this->normalize( 'Jane.Doe@Example.COM' ) );
	}

	public function testAddressWithoutAtSignIsRejected(): void {
		$this->assertNull( NormalizedEmail::fromString( 'jane.example.com' ) );
	}

	public function testAddressWithTwoAtSignsIsRejected(): void {
		$this->assertNull( NormalizedEmail::fromString( 'jane@doe@example.com' ) );
	}

	public function testAddressWithoutLocalPartIsRejected(): void {
		$this->assertNull( NormalizedEmail::fromString( '@example.com' ) );
	}

	public function testAddressWithoutDomainPartIsRejected(): void {
		$this->assertNull( NormalizedEmail::fromString( 'jane@' ) );
	}

	public function testAddressWithInternalWhitespaceIsRejected(): void {
		$this->assertNull( NormalizedEmail::fromString( 'ja ne@example.com' ) );
	}

	public function testEmptyStringIsRejected(): void {
		$this->assertNull( NormalizedEmail::fromString( '' ) );
	}

	public function testDomainIsThePartAfterTheAtSign(): void {
		$email = NormalizedEmail::fromString( 'jane@Sub.Example.com' );

		$this->assertNotNull( $email );
		$this->assertSame( 'sub.example.com', $email->domain() );
	}

	public function testHashDoesNotContainTheAddress(): void {
		$this->assertStringNotContainsString( 'jane', $this->hashOf( 'jane@example.com' ) );
	}

	public function testHashIsTheSameForTheSameAddress(): void {
		$this->assertSame( $this->hashOf( 'jane@example.com' ), $this->hashOf( 'jane@example.com' ) );
	}

	public function testHashDiffersBetweenAddresses(): void {
		$this->assertNotSame( $this->hashOf( 'jane@example.com' ), $this->hashOf( 'john@example.com' ) );
	}

	private function normalize( string $input ): ?string {
		return NormalizedEmail::fromString( $input )?->value;
	}

	private function hashOf( string $input ): string {
		$email = NormalizedEmail::fromString( $input );

		$this->assertNotNull( $email );

		return $email->hash();
	}

}
