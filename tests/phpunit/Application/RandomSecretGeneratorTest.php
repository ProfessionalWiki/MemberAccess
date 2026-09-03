<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\RandomSecretGenerator;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\RandomSecretGenerator
 */
class RandomSecretGeneratorTest extends TestCase {

	public function testCodeIsSixDigits(): void {
		$this->assertMatchesRegularExpression( '/^\d{6}$/', ( new RandomSecretGenerator() )->generateCode() );
	}

	public function testShortCodesAreLeftPaddedToSixDigits(): void {
		$generator = new RandomSecretGenerator();

		foreach ( range( 1, 200 ) as $ignored ) {
			$this->assertSame( 6, strlen( $generator->generateCode() ) );
		}
	}

	public function testCodesDiffer(): void {
		$generator = new RandomSecretGenerator();

		$codes = array_map( static fn (): string => $generator->generateCode(), range( 1, 20 ) );

		$this->assertGreaterThan( 15, count( array_unique( $codes ) ) );
	}

	public function testHandleIsLongEnoughToBeUnguessable(): void {
		$this->assertGreaterThanOrEqual( 32, strlen( ( new RandomSecretGenerator() )->generateHandle() ) );
	}

	public function testHandlesDiffer(): void {
		$generator = new RandomSecretGenerator();

		$this->assertNotSame( $generator->generateHandle(), $generator->generateHandle() );
	}

}
