<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\Auth;

use MediaWiki\Auth\AbstractPrimaryAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Auth\TemporaryPasswordAuthenticationRequest;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserRigorOptions;
use ProfessionalWiki\MemberAccess\Application\AllowlistMatcher;
use ProfessionalWiki\MemberAccess\Application\CodeLifetime;
use ProfessionalWiki\MemberAccess\Application\CodeRequestOutcome;
use ProfessionalWiki\MemberAccess\Application\CodeVerificationOutcome;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use ProfessionalWiki\MemberAccess\Application\RequestCodeUseCase;
use ProfessionalWiki\MemberAccess\Application\VerifyCodeUseCase;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Logs members in with a one-time code mailed to their address, which also becomes their username.
 *
 * A primary provider rather than a PluggableAuth plugin, because only core's newUI loop can ask for
 * the code and come back to the same provider with the login still in progress. Other primaries
 * keep working alongside it: this one abstains unless its own button was the one pressed.
 */
class MemberAuthenticationProvider extends AbstractPrimaryAuthenticationProvider {

	/**
	 * Holds the account name, address and group an account is to be provisioned with, for the
	 * moment between admitting a login and the account being created. Also written by the single
	 * sign-on gate, whose logins are created by another provider but provisioned here.
	 */
	public const PROVISIONING_SESSION_KEY = 'MemberAccessProvisioning';

	private const HANDLE_SESSION_KEY = 'MemberAccessCodeHandle';

	public function __construct(
		private readonly RequestCodeUseCase $codeRequests,
		private readonly VerifyCodeUseCase $codeVerification,
		private readonly AllowlistMatcher $matcher,
		private readonly MemberRepository $members,
		private readonly MemberProvisioner $provisioner,
		private readonly UserIdentityLookup $userLookup,
		private readonly LoggerInterface $auditLogger,
		private readonly CodeLifetime $codeLifetime
	) {
	}

	/**
	 * @param array<string, mixed> $options
	 * @return AuthenticationRequest[]
	 */
	public function getAuthenticationRequests( $action, array $options ) {
		return $action === AuthManager::ACTION_LOGIN ? [ new LoginCodeRequest() ] : [];
	}

	public function beginPrimaryAuthentication( array $reqs ) {
		$request = AuthenticationRequest::getRequestByClass( $reqs, LoginCodeRequest::class );

		if ( $request === null ) {
			return AuthenticationResponse::newAbstain();
		}

		$address = trim( $request->memberaccessEmail );

		if ( $address === '' ) {
			return AuthenticationResponse::newFail( wfMessage( 'memberaccess-auth-email-missing' ) );
		}

		$result = $this->codeRequests->requestCode( $address, $this->manager->getRequest()->getIP() );

		return match ( $result->outcome ) {
			CodeRequestOutcome::Accepted => $this->askForCode( (string)$result->handle ),
			CodeRequestOutcome::Throttled => AuthenticationResponse::newFail(
				wfMessage( 'memberaccess-auth-throttled' )
			),
			CodeRequestOutcome::InvalidEmail => AuthenticationResponse::newFail(
				wfMessage( 'memberaccess-auth-email-invalid' )
			)
		};
	}

	private function askForCode( string $handle ): AuthenticationResponse {
		$this->manager->setAuthenticationSessionData( self::HANDLE_SESSION_KEY, $handle );

		return AuthenticationResponse::newUI(
			[ new EnterCodeRequest() ],
			wfMessage( 'memberaccess-auth-code-sent', $this->codeLifetime->inMinutes() )
		);
	}

	/**
	 * @param AuthenticationRequest[] $reqs
	 */
	public function continuePrimaryAuthentication( array $reqs ) {
		$request = AuthenticationRequest::getRequestByClass( $reqs, EnterCodeRequest::class );
		$handle = $this->manager->getAuthenticationSessionData( self::HANDLE_SESSION_KEY );

		if ( $request === null || !is_string( $handle ) ) {
			return $this->refuse( 'Code entry continued without a code request in the session' );
		}

		$result = $this->codeVerification->verify( $handle, trim( $request->memberaccessCode ) );

		return match ( $result->outcome ) {
			CodeVerificationOutcome::Pass => $this->admit( (string)$result->email ),
			CodeVerificationOutcome::RetryableFailure => AuthenticationResponse::newUI(
				[ new EnterCodeRequest() ],
				wfMessage( 'memberaccess-auth-code-wrong', $result->attemptsRemaining ),
				'error'
			),
			CodeVerificationOutcome::Burned => AuthenticationResponse::newFail(
				wfMessage( 'memberaccess-auth-code-expired' )
			)
		};
	}

	/**
	 * The address is proven at this point. What remains is whether it is still admitted, and
	 * whether the account it maps to is really this member's.
	 */
	private function admit( string $verifiedAddress ): AuthenticationResponse {
		$email = NormalizedEmail::fromString( $verifiedAddress );
		$group = $email === null ? null : $this->matcher->match( $email );

		if ( $email === null || $group === null ) {
			$this->auditLogger->info( 'Proven address is not admitted by the allowlist', [
				'email' => NormalizedEmail::hashOf( $verifiedAddress )
			] );

			return AuthenticationResponse::newFail( wfMessage( 'memberaccess-auth-not-authorized' ) );
		}

		$username = $this->usernameFor( $email );

		if ( $username === null ) {
			return $this->refuse( 'Proven address cannot be used as a username', $email );
		}

		if ( $this->accountIsSomeoneElses( $username, $email ) ) {
			return $this->refuse( 'Derived username belongs to another account', $email );
		}

		$this->manager->setAuthenticationSessionData(
			self::PROVISIONING_SESSION_KEY,
			( new PendingProvisioning( username: $username, email: $email, groupId: $group->id ) )->toSessionData()
		);
		$this->manager->setAuthenticationSessionData( AuthManager::REMEMBER_ME, true );

		return AuthenticationResponse::newPass( $username );
	}

	/**
	 * The address in its MediaWiki username form: lowercased, first letter capitalised, and put
	 * through title normalisation, which turns underscores into spaces.
	 */
	private function usernameFor( NormalizedEmail $email ): ?string {
		return $this->canonicalNameOf( $email->value );
	}

	private function canonicalNameOf( string $username ): ?string {
		$canonical = $this->userNameUtils->getCanonical( $username, UserRigorOptions::RIGOR_USABLE );

		return $canonical === false ? null : $canonical;
	}

	/**
	 * An address proves a mailbox, never an account that was made some other way, so an existing
	 * account is only this member's when the roster says the two belong together.
	 */
	private function accountIsSomeoneElses( string $username, NormalizedEmail $email ): bool {
		$user = $this->userLookup->getUserIdentityByName( $username, IDBAccessObject::READ_LATEST );

		if ( $user === null || !$user->isRegistered() ) {
			return false;
		}

		// Read as recently as the account itself was, or a member provisioned moments ago looks
		// like somebody else's account and is turned away.
		return $this->members->getMember( $user->getId(), ReadConsistency::UpToDate )?->email !== $email->value;
	}

	private function refuse( string $reason, ?NormalizedEmail $email = null ): AuthenticationResponse {
		$this->auditLogger->warning( $reason, $email === null ? [] : [ 'email' => $email->hash() ] );

		return AuthenticationResponse::newFail( wfMessage( 'memberaccess-auth-failed' ) );
	}

	/**
	 * Called for every account created in this session, whichever provider caused it, so the one
	 * waiting to be provisioned is recognised by the name it was admitted under. Another account
	 * created meanwhile, a temporary user for instance, must not be handed the membership meant for
	 * the member. The name is canonicalised, since not every provider normalises the name it
	 * creates an account under.
	 */
	public function autoCreatedAccount( $user, $source ): void {
		$provisioning = PendingProvisioning::fromSessionData(
			$this->manager->getAuthenticationSessionData( self::PROVISIONING_SESSION_KEY )
		);

		if ( $provisioning === null || $user->getName() !== $this->canonicalNameOf( $provisioning->username ) ) {
			return;
		}

		// Forgotten only once it has been provisioned, or a failure would take the login with it and
		// leave the account that was created behind with nothing left to make it a member.
		$this->provisioner->provision( $user, $provisioning->email, $provisioning->groupId );
		$this->manager->removeAuthenticationSessionData( self::PROVISIONING_SESSION_KEY );
	}

	public function testUserExists( $username, $flags = IDBAccessObject::READ_NORMAL ) {
		$user = $this->userLookup->getUserIdentityByName( $username, $flags );

		return $user !== null
			&& $user->isRegistered()
			&& $this->members->getMember( $user->getId(), $this->consistencyFor( $flags ) ) !== null;
	}

	/**
	 * @param int $flags One of the IDBAccessObject READ_ constants
	 */
	private function consistencyFor( int $flags ): ReadConsistency {
		return $flags === IDBAccessObject::READ_NORMAL ? ReadConsistency::MayBeStale : ReadConsistency::UpToDate;
	}

	/**
	 * A password would be a way in that the allowlist does not govern, so members get none: neither
	 * one they set themselves, nor a temporary one mailed by a password reset.
	 *
	 * Requests that name nobody are left to the other providers, since Special:PasswordReset asks
	 * with one of those whether resetting is possible on this wiki at all.
	 */
	public function providerAllowsAuthenticationDataChange( AuthenticationRequest $req, $checkData = true ) {
		if ( $this->isPasswordRequest( $req ) && $this->isMemberName( $req->username ) ) {
			return StatusValue::newFatal( 'memberaccess-auth-password-refused' );
		}

		return StatusValue::newGood( 'ignored' );
	}

	private function isPasswordRequest( AuthenticationRequest $req ): bool {
		return $req instanceof PasswordAuthenticationRequest
			|| $req instanceof TemporaryPasswordAuthenticationRequest;
	}

	private function isMemberName( ?string $username ): bool {
		$canonical = $username === null
			? false
			: $this->userNameUtils->getCanonical( $username, UserRigorOptions::RIGOR_USABLE );

		return $canonical !== false && $this->testUserExists( $canonical, IDBAccessObject::READ_LATEST );
	}

	public function providerChangeAuthenticationData( AuthenticationRequest $req ): void {
	}

	/**
	 * Accounts are created by logging in with a code, never through account creation, and never by
	 * linking to a login elsewhere: this provider has no linking methods for core to reach.
	 */
	public function accountCreationType() {
		return self::TYPE_NONE;
	}

	public function beginPrimaryAccountCreation( $user, $creator, array $reqs ) {
		return AuthenticationResponse::newAbstain();
	}

}
