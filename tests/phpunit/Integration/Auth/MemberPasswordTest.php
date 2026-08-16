<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\Integration\Auth;

use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\MemberAccessExtension;
use ProfessionalWiki\MemberAccess\Tests\TestDoubles\SpyEmailer;
use StatusValue;

/**
 * A member proves who they are by reading their mailbox. A password would be a second way in that
 * the allowlist does not govern, so the extension refuses to let one be set or mailed.
 *
 * @group Database
 * @covers \ProfessionalWiki\MemberAccess\EntryPoints\Auth\MemberAuthenticationProvider
 */
class MemberPasswordTest extends ApiTestCase {

	use AuthenticationProviderRegistration;

	private const NEW_PASSWORD = 'a-password-long-enough-to-be-valid';

	private SpyEmailer $emailer;

	protected function setUp(): void {
		parent::setUp();

		$this->emailer = new SpyEmailer();
		$this->setService( 'Emailer', $this->emailer );
		$this->registerOurAuthenticationProvider();

		$this->overrideConfigValues( [
			MainConfigNames::EnableEmail => true,
			MainConfigNames::PasswordResetRoutes => [ 'username' => true, 'email' => true ],
			MainConfigNames::ReauthenticateTime => [ 'default' => -1 ]
		] );
	}

	public function testMemberCannotSetAPasswordThroughTheApi(): void {
		$member = $this->newMember( 'jane@example.com' );

		$this->expectApiErrorCode( 'badrequest' );
		$this->changePasswordThroughTheApi( $member );
	}

	public function testAccountThatIsNoMemberCanSetAPasswordThroughTheApi(): void {
		$result = $this->changePasswordThroughTheApi( $this->getMutableTestUser()->getUser() );

		$this->assertSame( 'success', $result['changeauthenticationdata']['status'] );
	}

	public function testPasswordChangeForAMemberIsRefused(): void {
		$member = $this->newMember( 'jane@example.com' );

		$status = $this->allowsPasswordChangeFor( $member );

		$this->assertFalse( $status->isGood() );
		$this->assertTrue( $status->hasMessage( 'memberaccess-auth-password-refused' ) );
	}

	/**
	 * Answering a member's address differently from any other address would make the reset form a
	 * way to ask who is a member, which the login flow is careful never to tell.
	 */
	public function testPasswordResetForAMemberAnswersLikeAnAccountThatIsNotThere(): void {
		$member = $this->newMember( 'jane@example.com' );

		$status = $this->resetPasswordOf( $member->getName(), null );

		$this->assertEquals( $this->resetPasswordOf( 'Nobody', null ), $status );
		$this->assertSame( [], $this->emailer->getSentMails() );
	}

	public function testPasswordResetByEmailAddressAlsoLeavesAMemberAlone(): void {
		$member = $this->newMember( 'jane@example.com' );

		$status = $this->resetPasswordOf( null, $member->getEmail() );

		$this->assertEquals( $this->resetPasswordOf( null, 'stranger@example.com' ), $status );
		$this->assertSame( [], $this->emailer->getSentMails() );
	}

	public function testPasswordChangeOfAnAccountThatIsNoMemberIsAllowed(): void {
		$status = $this->allowsPasswordChangeFor( $this->getMutableTestUser()->getUser() );

		$this->assertTrue( $status->isGood() );
	}

	public function testPasswordResetOfAnAccountThatIsNoMemberStillMails(): void {
		$staff = $this->getMutableTestUser()->getUser();
		$staff->setEmail( 'staff@example.com' );
		$staff->confirmEmail();
		$staff->saveSettings();

		$status = $this->resetPasswordOf( $staff->getName(), null );

		$this->assertTrue( $status->isGood() );
		$this->assertCount( 1, $this->emailer->getSentMails() );
	}

	/**
	 * Special:PasswordReset asks whether resetting is possible at all with a request that names
	 * nobody. Answering that one with a refusal would take the feature away from the whole wiki.
	 */
	public function testResettingPasswordsStaysAvailableOnTheWiki(): void {
		$this->assertTrue( $this->getServiceContainer()->getPasswordReset()->isEnabled()->isGood() );
	}

	/**
	 * @return array<mixed>
	 */
	private function changePasswordThroughTheApi( User $user ): array {
		[ $tokens ] = $this->doApiRequest( [ 'action' => 'query', 'meta' => 'tokens' ], null, false, $user );

		[ $result ] = $this->doApiRequest( [
			'action' => 'changeauthenticationdata',
			'changeauthrequest' => PasswordAuthenticationRequest::class,
			'password' => self::NEW_PASSWORD,
			'retype' => self::NEW_PASSWORD,
			'changeauthtoken' => $tokens['query']['tokens']['csrftoken']
		], null, false, $user );

		return $result;
	}

	private function allowsPasswordChangeFor( User $user ): StatusValue {
		$request = new PasswordAuthenticationRequest();
		$request->username = $user->getName();
		$request->password = self::NEW_PASSWORD;
		$request->retype = self::NEW_PASSWORD;

		return $this->getServiceContainer()->getAuthManager()
			->allowsAuthenticationDataChange( $request, true );
	}

	private function resetPasswordOf( ?string $username, ?string $email ): StatusValue {
		$status = $this->getServiceContainer()->getPasswordReset()->execute(
			$this->getTestSysop()->getUser(),
			$username,
			$email
		);

		DeferredUpdates::doUpdates();

		return $status;
	}

	private function newMember( string $email ): User {
		$extension = MemberAccessExtension::getInstance();
		$user = $this->getServiceContainer()->getUserFactory()->newFromName( $email );

		$this->assertNotNull( $user );
		$user->addToDatabase();
		$user->setEmail( $email );
		$user->confirmEmail();
		$user->saveSettings();

		$normalized = NormalizedEmail::fromString( $email );
		$this->assertNotNull( $normalized );

		$extension->newMemberRepository()->recordMember(
			userId: $user->getId(),
			email: $normalized,
			groupId: $extension->newMemberGroupRepository()->createGroup( 'Acme' )->id
		);

		return $user;
	}

}
