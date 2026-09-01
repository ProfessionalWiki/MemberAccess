<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration;

use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\User\UserRigorOptions;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\OpaqueUsername;
use ProfessionalWiki\MemberAccess\Application\UsernameGenerator;
use ProfessionalWiki\MemberAccess\Application\UsernameMinter;
use ProfessionalWiki\MemberAccess\Persistence\MediaWikiUsernameMinter;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\UsernameSequenceGenerator;
use RuntimeException;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\Persistence\MediaWikiUsernameMinter
 */
class MediaWikiUsernameMinterTest extends MediaWikiIntegrationTestCase {

	private const string TAKEN_NAME = 'Member AAAAAA';
	private const string FREE_NAME = 'Member BBBBBB';

	public function testMintedNameIsOpaque(): void {
		$this->assertTrue( OpaqueUsername::isOpaque( $this->newMinter( new OpaqueUsername() )->mintUsername() ) );
	}

	public function testMintedNameCanHaveAnAccountCreatedUnderIt(): void {
		$name = $this->newMinter( new OpaqueUsername() )->mintUsername();

		$this->assertTrue( $this->getServiceContainer()->getUserNameUtils()->isCreatable( $name ) );
	}

	public function testNameAnAccountAlreadyHoldsIsPassedOverForTheNextOne(): void {
		$this->createAccountNamed( self::TAKEN_NAME );

		$minter = $this->newMinter( new UsernameSequenceGenerator( [ self::TAKEN_NAME, self::FREE_NAME ] ) );

		$this->assertSame( self::FREE_NAME, $minter->mintUsername() );
	}

	/**
	 * The name is handed back in the form the account is created under, which is also the form it
	 * is found by afterwards, and not always the form it was drawn in.
	 */
	public function testNameIsMintedInTheFormAnAccountIsCreatedUnder(): void {
		$minter = $this->newMinter( new UsernameSequenceGenerator( [ 'member_AB2345' ] ) );

		$this->assertSame( 'Member AB2345', $minter->mintUsername() );
	}

	public function testNameNoAccountCouldBeCreatedUnderIsPassedOverForTheNextOne(): void {
		$minter = $this->newMinter( new UsernameSequenceGenerator( [ '', self::FREE_NAME ] ) );

		$this->assertSame( self::FREE_NAME, $minter->mintUsername() );
	}

	/**
	 * The account a name is minted for is created moments later, so an account a replica has not
	 * caught up with yet still holds its name as far as the next minting is concerned.
	 */
	public function testNameIsFreeOnlyWhenThePrimaryDatabaseSaysSo(): void {
		$minter = new MediaWikiUsernameMinter(
			generator: new UsernameSequenceGenerator( [ self::TAKEN_NAME, self::FREE_NAME ] ),
			userNameUtils: $this->getServiceContainer()->getUserNameUtils(),
			userLookup: $this->newLookupOnlyThePrimaryKnowsTheAccountIn( self::TAKEN_NAME )
		);

		$this->assertSame( self::FREE_NAME, $minter->mintUsername() );
	}

	private function newLookupOnlyThePrimaryKnowsTheAccountIn( string $name ): UserIdentityLookup {
		$lookup = $this->createMock( UserIdentityLookup::class );
		$lookup->method( 'getUserIdentityByName' )->willReturnCallback(
			static fn ( string $asked, int $flags ): ?UserIdentityValue =>
				$asked === $name && $flags === IDBAccessObject::READ_LATEST
					? new UserIdentityValue( 1, $name )
					: null
		);

		return $lookup;
	}

	/**
	 * Handing back a name an account already holds would hand that account to whoever the name was
	 * minted for, so a minter that cannot find a free one refuses rather than settles.
	 */
	public function testMintingRefusesWhenNoFreeNameIsFound(): void {
		$this->createAccountNamed( self::TAKEN_NAME );
		$minter = $this->newMinter( new UsernameSequenceGenerator( [ self::TAKEN_NAME ] ) );

		$this->expectException( RuntimeException::class );
		$minter->mintUsername();
	}

	private function newMinter( UsernameGenerator $generator ): UsernameMinter {
		return new MediaWikiUsernameMinter(
			generator: $generator,
			userNameUtils: $this->getServiceContainer()->getUserNameUtils(),
			userLookup: $this->getServiceContainer()->getUserIdentityLookup()
		);
	}

	private function createAccountNamed( string $name ): void {
		$user = $this->getServiceContainer()->getUserFactory()
			->newFromName( $name, UserRigorOptions::RIGOR_CREATABLE );

		$this->assertNotNull( $user );
		$user->addToDatabase();
	}

}
