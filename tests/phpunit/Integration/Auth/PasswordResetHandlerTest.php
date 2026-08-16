<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;

/**
 * Runs the hook itself rather than the handler, so that the registration in extension.json is
 * covered as well: an unregistered handler would leave members in the reset, where the refusal that
 * follows tells whoever asked that the address is a member's.
 *
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\PasswordResetHandler
 */
class PasswordResetHandlerTest extends MediaWikiIntegrationTestCase {

	public function testMemberIsDroppedFromTheReset(): void {
		$users = [ $this->newMember() ];

		$this->submitPasswordReset( $users );

		$this->assertSame( [], $users );
	}

	public function testAccountThatIsNoMemberIsLeftInTheReset(): void {
		$staff = $this->newAccount();
		$users = [ $staff ];

		$this->submitPasswordReset( $users );

		$this->assertSame( [ $staff ], $users );
	}

	/**
	 * One address can belong to a member and to staff at once, and only the member has no password
	 * to reset, so the reset goes ahead for the rest.
	 */
	public function testOnlyTheMembersAreDropped(): void {
		$staff = $this->newAccount();
		$users = [ $this->newMember(), $staff, $this->newMember() ];

		$this->submitPasswordReset( $users );

		$this->assertSame( [ $staff ], $users );
	}

	/**
	 * @param User[] &$users
	 */
	private function submitPasswordReset( array &$users ): void {
		$error = '';

		$this->getServiceContainer()->getHookContainer()->run(
			'SpecialPasswordResetOnSubmit',
			[ &$users, [ 'Username' => null, 'Email' => 'shared@example.com' ], &$error ]
		);
	}

	private function newMember(): User {
		$user = $this->newAccount();
		$email = NormalizedEmail::fromString( 'member' . $user->getId() . '@example.com' );

		$this->assertNotNull( $email );

		MemberAccessExtension::getInstance()->newMemberRepository()
			->recordMember( userId: $user->getId(), email: $email, groupId: 1 );

		return $user;
	}

	private function newAccount(): User {
		return $this->getMutableTestUser()->getUser();
	}

}
