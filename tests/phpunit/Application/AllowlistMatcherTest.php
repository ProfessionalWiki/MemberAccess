<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Application;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistMatcher;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryAllowlistRepository;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\InMemoryMemberGroupRepository;

/**
 * @covers \ProfessionalWiki\MemberAccess\Application\AllowlistMatcher
 */
class AllowlistMatcherTest extends TestCase {

	private InMemoryMemberGroupRepository $groups;
	private InMemoryAllowlistRepository $allowlist;

	protected function setUp(): void {
		$this->groups = new InMemoryMemberGroupRepository();
		$this->allowlist = new InMemoryAllowlistRepository( $this->groups );
	}

	public function testListedAddressMatchesItsGroup(): void {
		$this->listValue( 'first@example.com', 'First' );
		$this->listValue( 'jane@example.com', 'Jane' );
		$this->listValue( 'last@example.com', 'Last' );

		$this->assertMatchedGroupName( 'Jane', 'jane@example.com' );
	}

	public function testAddressAtAListedDomainMatchesTheDomainGroup(): void {
		$this->listValue( '@other.com', 'Other' );
		$this->listValue( '@example.com', 'Example' );

		$this->assertMatchedGroupName( 'Example', 'jane@example.com' );
	}

	public function testListedAddressWinsOverItsListedDomain(): void {
		$this->listValue( '@example.com', 'Domain' );
		$this->listValue( 'jane@example.com', 'Address' );

		$this->assertMatchedGroupName( 'Address', 'jane@example.com' );
	}

	public function testAddressIsNormalizedBeforeMatching(): void {
		$this->listValue( 'jane@example.com', 'Jane' );

		$this->assertMatchedGroupName( 'Jane', '  JANE@Example.COM ' );
	}

	public function testDomainOfTheAddressIsNormalizedBeforeMatching(): void {
		$this->listValue( '@example.com', 'Example' );

		$this->assertMatchedGroupName( 'Example', 'Jane@EXAMPLE.com' );
	}

	public function testUnlistedAddressMatchesNothing(): void {
		$this->listValue( 'jane@example.com', 'Jane' );
		$this->listValue( '@example.org', 'Example org' );

		$this->assertNull( $this->newMatcher()->matchEmail( 'john@example.net' ) );
	}

	public function testAddressAtAnUnlistedSubdomainMatchesNothing(): void {
		$this->listValue( '@example.com', 'Example' );

		$this->assertNull( $this->newMatcher()->matchEmail( 'jane@mail.example.com' ) );
	}

	public function testTextThatIsNotAnAddressMatchesNothing(): void {
		$this->listValue( '@example.com', 'Example' );

		$this->assertNull( $this->newMatcher()->matchEmail( 'example.com' ) );
	}

	private function listValue( string $value, string $groupName ): void {
		$allowlistValue = AllowlistValue::fromString( $value );

		$this->assertNotNull( $allowlistValue );

		$this->allowlist->addEntry(
			groupId: $this->groups->createGroup( $groupName )->id,
			value: $allowlistValue,
			actorId: 1
		);
	}

	private function assertMatchedGroupName( string $expectedName, string $email ): void {
		$this->assertSame( $expectedName, $this->newMatcher()->matchEmail( $email )?->name );
	}

	private function newMatcher(): AllowlistMatcher {
		return new AllowlistMatcher( allowlist: $this->allowlist );
	}

}
