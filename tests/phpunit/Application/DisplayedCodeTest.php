<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\DisplayedCode;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\DisplayedCode
 */
class DisplayedCodeTest extends TestCase {

	public function testCodeIsShownInGroupsOfFour(): void {
		$this->assertSame( '4063 2370', DisplayedCode::grouped( '40632370' ) );
	}

	/**
	 * The length is the wiki's generator's to choose, so a code that does not divide into whole
	 * groups is shown with a shorter last one rather than not shown at all.
	 */
	public function testCodeThatDoesNotFillItsLastGroupKeepsTheRemainder(): void {
		$this->assertSame( '1234 56', DisplayedCode::grouped( '123456' ) );
	}

	public function testEmptyCodeIsShownAsNothing(): void {
		$this->assertSame( '', DisplayedCode::grouped( '' ) );
	}

	public function testGroupedLengthLeavesRoomForTheSpacesBetweenTheGroups(): void {
		$this->assertSame( 9, DisplayedCode::groupedLength( 8 ) );
	}

	public function testGroupedLengthOfACodeEndingInAShortGroup(): void {
		$this->assertSame( 7, DisplayedCode::groupedLength( 6 ) );
	}

	public function testGroupingIsTakenBackOff(): void {
		$this->assertSame( '40632370', DisplayedCode::ungrouped( '4063 2370' ) );
	}

	public function testACodeTypedWithoutTheGroupingIsLeftAsItIs(): void {
		$this->assertSame( '40632370', DisplayedCode::ungrouped( '40632370' ) );
	}

	/**
	 * What a mail client hands to a clipboard is its own affair, and a member pasting from one is
	 * not to be refused their own code over it.
	 */
	public function testEverySortOfSpaceIsTakenBackOff(): void {
		$this->assertSame( '40632370', DisplayedCode::ungrouped( " 4063\t2370\u{00A0}" ) );
	}

	public function testShowingACodeAndTakingItBackReturnsWhatWasIssued(): void {
		$this->assertSame( '40632370', DisplayedCode::ungrouped( DisplayedCode::grouped( '40632370' ) ) );
	}

}
