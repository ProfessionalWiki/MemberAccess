<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\EntryKind;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\AllowlistValue
 */
class AllowlistValueTest extends TestCase {

	public function testAddressBecomesAnEmailEntry(): void {
		$value = AllowlistValue::fromString( 'Jane@Example.com' );

		$this->assertNotNull( $value );
		$this->assertSame( 'jane@example.com', $value->value );
		$this->assertSame( EntryKind::Email, $value->kind );
	}

	public function testLeadingAtSignMakesADomainEntry(): void {
		$value = AllowlistValue::fromString( '  @Example.COM ' );

		$this->assertNotNull( $value );
		$this->assertSame( '@example.com', $value->value );
		$this->assertSame( EntryKind::Domain, $value->kind );
	}

	/**
	 * A pasted address often arrives with a non-breaking space in front, which trim leaves in
	 * place, turning "@example.com" into an address entry that can never match anything.
	 */
	public function testNonBreakingSpaceAroundADomainIsTrimmedAway(): void {
		$value = AllowlistValue::fromString( "\u{00A0}@example.com\u{00A0}" );

		$this->assertNotNull( $value );
		$this->assertSame( '@example.com', $value->value );
		$this->assertSame( EntryKind::Domain, $value->kind );
	}

	public function testValueWithAnInvisibleCharacterInItIsRejected(): void {
		$this->assertNull( AllowlistValue::fromString( "ja\u{200B}ne@example.com" ) );
	}

	public function testValueThatDoesNotFitTheColumnIsRejected(): void {
		$this->assertNull( AllowlistValue::fromString( '@' . str_repeat( 'a', 255 ) . '.com' ) );
	}

	public function testValueOfExactlyTheLongestAcceptedLengthIsKept(): void {
		$domain = str_repeat( 'a', AllowlistValue::MAX_LENGTH - 5 ) . '.com';

		$this->assertNotNull( AllowlistValue::fromString( '@' . $domain ) );
	}

	public function testTooLongValueIsReportedAsSuch(): void {
		$this->assertTrue( AllowlistValue::exceedsMaxLength( str_repeat( 'a', 250 ) . '@example.com' ) );
	}

	public function testValueThatFitsIsNotReportedAsTooLong(): void {
		$this->assertFalse( AllowlistValue::exceedsMaxLength( 'jane@example.com' ) );
	}

	public function testDomainWithoutNameIsRejected(): void {
		$this->assertNull( AllowlistValue::fromString( '@' ) );
	}

	public function testDomainContainingAnAtSignIsRejected(): void {
		$this->assertNull( AllowlistValue::fromString( '@ex@ample.com' ) );
	}

	public function testDomainContainingWhitespaceIsRejected(): void {
		$this->assertNull( AllowlistValue::fromString( '@exa mple.com' ) );
	}

	public function testBareDomainWithoutAtSignIsRejected(): void {
		$this->assertNull( AllowlistValue::fromString( 'example.com' ) );
	}

	public function testEmptyStringIsRejected(): void {
		$this->assertNull( AllowlistValue::fromString( '' ) );
	}

	public function testForEmailKeepsTheNormalizedAddress(): void {
		$value = AllowlistValue::forEmail( $this->email( 'jane@example.com' ) );

		$this->assertSame( 'jane@example.com', $value->value );
		$this->assertSame( EntryKind::Email, $value->kind );
	}

	public function testForDomainOfBuildsTheDomainEntryOfAnAddress(): void {
		$value = AllowlistValue::forDomainOf( $this->email( 'jane@example.com' ) );

		$this->assertSame( '@example.com', $value->value );
		$this->assertSame( EntryKind::Domain, $value->kind );
	}

	private function email( string $address ): NormalizedEmail {
		$email = NormalizedEmail::fromString( $address );

		$this->assertNotNull( $email );

		return $email;
	}

}
