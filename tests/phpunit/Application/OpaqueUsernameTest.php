<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\OpaqueUsername;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\OpaqueUsername
 */
class OpaqueUsernameTest extends TestCase {

	public function testGeneratedNameIsThePrefixFollowedBySixCharactersOfTheAlphabet(): void {
		$this->assertMatchesRegularExpression( '/^Member [A-Z2-7]{6}$/', $this->generate() );
	}

	/**
	 * A name that says nothing about who holds it also has to say nothing about who else does, so
	 * two members never arrive at one name because the name was never about them.
	 */
	public function testTwoNamesAreNotTheSame(): void {
		$this->assertNotSame( $this->generate(), $this->generate() );
	}

	public function testGeneratedNameIsRecognisedAsOpaque(): void {
		$this->assertTrue( OpaqueUsername::isOpaque( $this->generate() ) );
	}

	/**
	 * A plugin creating the account makes the name unique by putting a number after it, which
	 * leaves a name that still identifies nobody.
	 */
	public function testNameAPluginNumberedIsStillOpaque(): void {
		$this->assertTrue( OpaqueUsername::isOpaque( $this->generate() . '2' ) );
	}

	/**
	 * @dataProvider nameThatIsNotOpaqueProvider
	 */
	public function testNameThatWasNotGeneratedHereIsNotOpaque( string $name ): void {
		$this->assertFalse( OpaqueUsername::isOpaque( $name ) );
	}

	public static function nameThatIsNotOpaqueProvider(): iterable {
		yield 'an address' => [ 'Jane@example.com' ];
		yield 'a name an identity provider settled on' => [ 'Jane of Acme' ];
		yield 'the prefix alone' => [ 'Member' ];
		yield 'the prefix with nothing after it' => [ 'Member ' ];
		yield 'a name that is only prefixed by one' => [ 'Member A7K2M4 of Acme' ];
		yield 'characters outside the alphabet' => [ 'Member A7K2M0' ];
		yield 'lowercase characters' => [ 'Member a7k2m4' ];
		yield 'too few characters' => [ 'Member A7K2M' ];
		yield 'a seventh character that is no number' => [ 'Member A7K2M4X' ];
		yield 'an opaque name with a line break after it' => [ "Member A7K2M4\n" ];
	}

	private function generate(): string {
		return ( new OpaqueUsername() )->generateUsername();
	}

}
