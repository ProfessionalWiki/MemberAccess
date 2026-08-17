<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\EntryPoints\Auth;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\PendingProvisioning;

/**
 * What is read back out of the authentication session. The session survives requests and is
 * written by more than one route, so anything but what was put there has to come back as nothing:
 * provisioning half a member would leave an account nobody can log into again.
 *
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\PendingProvisioning
 */
class PendingProvisioningTest extends TestCase {

	public function testWhatWasStoredComesBack(): void {
		$provisioning = PendingProvisioning::fromSessionData( $this->newProvisioning( 3 )->toSessionData() );

		$this->assertSame( 'SsoNewcomer', $provisioning?->username );
		$this->assertSame( 'jane@example.com', $provisioning?->email->value );
		$this->assertSame( 3, $provisioning?->groupId );
	}

	public function testMemberNoGroupAdmittedComesBackWithoutOne(): void {
		$provisioning = PendingProvisioning::fromSessionData( $this->newProvisioning( null )->toSessionData() );

		$this->assertNotNull( $provisioning );
		$this->assertNull( $provisioning->groupId );
	}

	public function testEmptySessionHoldsNoProvisioning(): void {
		$this->assertNull( PendingProvisioning::fromSessionData( null ) );
	}

	/**
	 * The session holds whatever was put under the key, which need not be an array at all.
	 * An object is the near miss worth pinning: reading a key off one is an error rather than
	 * the nothing that reading a key off a string or a number gives.
	 */
	public function testValueThatIsNoArrayHoldsNoProvisioning(): void {
		$this->assertNull( PendingProvisioning::fromSessionData( (object)[ 'username' => 'SsoNewcomer' ] ) );
	}

	public function testMissingUsernameHoldsNoProvisioning(): void {
		$this->assertNull( PendingProvisioning::fromSessionData( $this->sessionDataWithout( 'username' ) ) );
	}

	public function testMissingAddressHoldsNoProvisioning(): void {
		$this->assertNull( PendingProvisioning::fromSessionData( $this->sessionDataWithout( 'email' ) ) );
	}

	/**
	 * Absent and null mean different things here: a member no allowlist entry admitted has a null
	 * group, so only the key being gone says the session data is not ours.
	 */
	public function testMissingGroupHoldsNoProvisioning(): void {
		$this->assertNull( PendingProvisioning::fromSessionData( $this->sessionDataWithout( 'groupId' ) ) );
	}

	public function testUsernameThatIsNoStringHoldsNoProvisioning(): void {
		$this->assertNull( PendingProvisioning::fromSessionData( $this->sessionDataWith( [ 'username' => 42 ] ) ) );
	}

	public function testAddressThatIsNoStringHoldsNoProvisioning(): void {
		$this->assertNull( PendingProvisioning::fromSessionData( $this->sessionDataWith( [ 'email' => 42 ] ) ) );
	}

	public function testGroupThatIsNoNumberHoldsNoProvisioning(): void {
		$this->assertNull( PendingProvisioning::fromSessionData( $this->sessionDataWith( [ 'groupId' => '3' ] ) ) );
	}

	public function testAddressThatIsNoAddressHoldsNoProvisioning(): void {
		$this->assertNull(
			PendingProvisioning::fromSessionData( $this->sessionDataWith( [ 'email' => 'not an address' ] ) )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function sessionDataWithout( string $key ): array {
		$data = $this->newProvisioning( 3 )->toSessionData();

		unset( $data[$key] );

		return $data;
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private function sessionDataWith( array $values ): array {
		return array_replace( $this->newProvisioning( 3 )->toSessionData(), $values );
	}

	private function newProvisioning( ?int $groupId ): PendingProvisioning {
		$email = NormalizedEmail::fromString( 'jane@example.com' );

		$this->assertNotNull( $email );

		return new PendingProvisioning( username: 'SsoNewcomer', email: $email, groupId: $groupId );
	}

}
