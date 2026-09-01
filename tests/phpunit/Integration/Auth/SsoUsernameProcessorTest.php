<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use Closure;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\OpaqueUsername;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\SsoUsernameProcessor;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\CrashingUsernameMinter;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\RefusingUsernameMinter;

/**
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\SsoUsernameProcessor
 */
class SsoUsernameProcessorTest extends MediaWikiIntegrationTestCase {

	private const string PLUGIN_NAME = 'Jane of Acme';

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( 'MemberAccessApplyAllowlistToSso', true );
		$this->allow( '@example.com' );
	}

	protected function tearDown(): void {
		MemberAccessExtension::getInstance()->recordSsoAddress( null );
		MemberAccessExtension::getInstance()->setUsernameMinterOverride( null );

		parent::tearDown();
	}

	public function testAdmittedAddressIsGivenAnOpaqueName(): void {
		$name = $this->processName( self::PLUGIN_NAME, 'jane@example.com' );

		$this->assertTrue( OpaqueUsername::isOpaque( (string)$name ) );
	}

	public function testTwoAdmittedLoginsAreGivenDifferentNames(): void {
		$first = $this->processName( self::PLUGIN_NAME, 'jane@example.com' );
		$second = $this->processName( self::PLUGIN_NAME, 'john@example.com' );

		$this->assertNotSame( $first, $second );
	}

	/**
	 * The claims hold the address only where the identity provider put it in a token. Everywhere
	 * else the plugin fetched it from the userinfo endpoint, and what it resolved is the only
	 * address there is to hold the login to the allowlist by.
	 */
	public function testAddressThePluginResolvedNamesALoginTheClaimsSayNothingAbout(): void {
		MemberAccessExtension::getInstance()->recordSsoAddress( 'jane@example.com' );

		$this->assertTrue( OpaqueUsername::isOpaque( (string)$this->process( self::PLUGIN_NAME, [] ) ) );
	}

	/**
	 * A wiki whose own processor rewrites addresses is holding the plugin and the extension to one
	 * list, since what it returns is what both go on with.
	 */
	public function testAddressThePluginResolvedIsPreferredOverTheOneInTheClaims(): void {
		MemberAccessExtension::getInstance()->recordSsoAddress( 'staff@other.example' );

		$this->assertSame( self::PLUGIN_NAME, $this->processName( self::PLUGIN_NAME, 'jane@example.com' ) );
	}

	/**
	 * A name that cannot be minted leaves the plugin's, which the authorization gate then refuses.
	 * Letting the failure out instead would put it on PluggableAuth's error screen for the visitor.
	 */
	public function testLoginKeepsThePluginsNameWhenNoNameCanBeMinted(): void {
		MemberAccessExtension::getInstance()->setUsernameMinterOverride( new RefusingUsernameMinter() );

		$this->assertSame( self::PLUGIN_NAME, $this->processName( self::PLUGIN_NAME, 'jane@example.com' ) );
	}

	/**
	 * The random source failing arrives as an exception that is no RuntimeException. It leaves the
	 * plugin's name the same way, rather than surfacing on PluggableAuth's error screen for the
	 * visitor.
	 */
	public function testLoginKeepsThePluginsNameWhenTheRandomSourceFails(): void {
		MemberAccessExtension::getInstance()->setUsernameMinterOverride( new CrashingUsernameMinter() );

		$this->assertSame( self::PLUGIN_NAME, $this->processName( self::PLUGIN_NAME, 'jane@example.com' ) );
	}

	/**
	 * Staff signing in through the identity provider are no members, and keep the name their
	 * plugin settled on.
	 */
	public function testAddressThatIsNotAdmittedKeepsThePluginsName(): void {
		$this->assertSame( self::PLUGIN_NAME, $this->processName( self::PLUGIN_NAME, 'staff@other.example' ) );
	}

	public function testLoginWithoutAnAddressKeepsThePluginsName(): void {
		$this->assertSame( self::PLUGIN_NAME, $this->process( self::PLUGIN_NAME, [] ) );
	}

	public function testPluginWithoutANameIsStillLeftWithoutOneWhenItIsNoMember(): void {
		$this->assertNull( $this->processName( null, 'staff@other.example' ) );
	}

	public function testPluginWithoutANameIsGivenAnOpaqueNameWhenItIsAMember(): void {
		$this->assertTrue( OpaqueUsername::isOpaque( (string)$this->processName( null, 'jane@example.com' ) ) );
	}

	/**
	 * A wiki that named its own processor keeps it: it runs first, and what it settles on is what
	 * a login that is no member is left with.
	 */
	public function testProcessorTheWikiConfiguredDecidesTheNameOfALoginThatIsNoMember(): void {
		$name = $this->processName( self::PLUGIN_NAME, 'staff@other.example', $this->processorNaming( 'Renamed' ) );

		$this->assertSame( 'Renamed', $name );
	}

	public function testProcessorTheWikiConfiguredIsRunForALoginThatIsAMember(): void {
		$namesSeen = [];
		$wrapped = static function ( ?string $name, array $attributes ) use ( &$namesSeen ): ?string {
			$namesSeen[] = $name;

			return $name;
		};

		$this->processName( self::PLUGIN_NAME, 'jane@example.com', Closure::fromCallable( $wrapped ) );

		$this->assertSame( [ self::PLUGIN_NAME ], $namesSeen );
	}

	/**
	 * A member's name is never one that identifies them, whatever the wiki's own processor makes
	 * of it.
	 */
	public function testNameFromTheWikisProcessorIsStillReplacedForAMember(): void {
		$name = $this->processName( self::PLUGIN_NAME, 'jane@example.com', $this->processorNaming( 'Jane' ) );

		$this->assertTrue( OpaqueUsername::isOpaque( (string)$name ) );
	}

	/**
	 * The processor names the account and the authorization gate then judges that name, with the
	 * identity provider's plugin in between. Nothing but this holds the two to one idea of what a
	 * member's account may be called.
	 */
	public function testNameTheProcessorMintsIsOneTheAuthorizationGateAdmits(): void {
		$name = $this->processName( self::PLUGIN_NAME, 'jane@example.com' );
		$login = $this->getServiceContainer()->getUserFactory()->newFromName( (string)$name );

		$this->assertNotNull( $login, 'the minted name has to be one an account can be created under' );
		$login->setEmail( 'jane@example.com' );

		$authorized = true;
		MemberAccessExtension::newSsoAuthorizationHandlerHookHandler()
			->onPluggableAuthUserAuthorization( $login, $authorized );

		$this->assertTrue( $authorized );
	}

	private function processorNaming( string $name ): Closure {
		return static fn ( ?string $preferredUsername, array $attributes ): string => $name;
	}

	private function processName( ?string $pluginName, string $email, ?Closure $wrapped = null ): ?string {
		return $this->process( $pluginName, [ 'email' => $email ], $wrapped );
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	private function process( ?string $pluginName, array $attributes, ?Closure $wrapped = null ): ?string {
		return $this->newProcessor( $wrapped )( $pluginName, $attributes );
	}

	private function newProcessor( ?Closure $wrapped ): SsoUsernameProcessor {
		return MemberAccessExtension::newSsoUsernameProcessor( $wrapped );
	}

	private function allow( string $value ): void {
		$extension = MemberAccessExtension::getInstance();
		$allowlistValue = AllowlistValue::fromString( $value );

		$this->assertNotNull( $allowlistValue );

		$extension->newAllowlistRepository()->addEntry(
			groupId: $extension->newMemberGroupRepository()->createGroup( 'Acme' )->id,
			value: $allowlistValue,
			actorId: 1
		);
	}

}
