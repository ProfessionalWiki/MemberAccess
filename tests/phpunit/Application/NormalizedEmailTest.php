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

	/**
	 * Whitespace MediaWiki's title normalisation folds away: two addresses telling each other
	 * apart only by it would arrive at one username, and the second one at that name is refused.
	 *
	 * @dataProvider unicodeWhitespaceProvider
	 */
	public function testAddressWithUnicodeWhitespaceIsRejected( string $address ): void {
		$this->assertNull( NormalizedEmail::fromString( $address ) );
	}

	public static function unicodeWhitespaceProvider(): iterable {
		yield 'no-break space in the local part' => [ "ja\u{00A0}ne@example.com" ];
		yield 'no-break space at the end, which trim() leaves' => [ "jane@example.com\u{00A0}" ];
		yield 'line separator' => [ "ja\u{2028}ne@example.com" ];
	}

	/**
	 * Bytes that are no text are no address either. Lowercasing replaces them with a question
	 * mark, which would turn every such address into the same one.
	 */
	public function testAddressThatIsNotValidTextIsRejected(): void {
		$this->assertNull( NormalizedEmail::fromString( "ja\xFFne@example.com" ) );
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
