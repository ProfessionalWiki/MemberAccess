<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\Auth;

use MediaWiki\Auth\AbstractPrimaryAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Auth\TemporaryPasswordAuthenticationRequest;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserRigorOptions;
use ProfessionalWiki\MemberAccess\Application\AllowlistMatcher;
use ProfessionalWiki\MemberAccess\Application\CodeLifetime;
use ProfessionalWiki\MemberAccess\Application\CodeLoginMode;
use ProfessionalWiki\MemberAccess\Application\CodeRequestOutcome;
use ProfessionalWiki\MemberAccess\Application\CodeVerificationOutcome;
use ProfessionalWiki\MemberAccess\Application\DisplayedCode;
use ProfessionalWiki\MemberAccess\Application\Member;
use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use ProfessionalWiki\MemberAccess\Application\RequestCodeUseCase;
use ProfessionalWiki\MemberAccess\Application\Schema;
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
		private readonly CodeLoginMode $mode,
		private readonly RequestCodeUseCase $codeRequests,
		private readonly VerifyCodeUseCase $codeVerification,
		private readonly AllowlistMatcher $matcher,
		private readonly MemberRepository $members,
		private readonly MemberProvisioner $provisioner,
		private readonly UserIdentityLookup $userLookup,
		private readonly UserGroupManager $userGroups,
		private readonly LoggerInterface $auditLogger,
		private readonly CodeLifetime $codeLifetime,
		private readonly string $readerGroup,
		private readonly Schema $schema
	) {
	}

	/**
	 * A route that is off has no button, which is also what keeps its request out of every
	 * submission MediaWiki accepts.
	 *
	 * Only the login form is offered one, so every other action is answered before the route is
	 * asked about at all: the answer is the same either way, and an account creation render has no
	 * reason to put the schema question to the database.
	 *
	 * @param array<string, mixed> $options
	 * @return AuthenticationRequest[]
	 */
	public function getAuthenticationRequests( $action, array $options ) {
		if ( $action !== AuthManager::ACTION_LOGIN ) {
			return [];
		}

		return $this->codeRouteIsOff() ? [] : [ new LoginCodeRequest() ];
	}

	/**
	 * The route is off where the wiki turned it off, and on a wiki that has not created the tables
	 * yet: without an allowlist to ask and a roster to write to, there is no route to offer.
	 */
	private function codeRouteIsOff(): bool {
		return $this->mode === CodeLoginMode::Off || $this->schema->isMissing();
	}

	public function beginPrimaryAuthentication( array $reqs ) {
		if ( $this->codeRouteIsOff() ) {
			return AuthenticationResponse::newAbstain();
		}

		$request = AuthenticationRequest::getRequestByClass( $reqs, LoginCodeRequest::class );

		if ( $request === null ) {
			return AuthenticationResponse::newAbstain();
		}

		$address = $request->address();

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
		if ( $this->codeRouteIsOff() ) {
			return $this->refuse( 'Code entry continued while the code login route is off' );
		}

		$request = AuthenticationRequest::getRequestByClass( $reqs, EnterCodeRequest::class );
		$handle = $this->manager->getAuthenticationSessionData( self::HANDLE_SESSION_KEY );

		if ( $request === null || !is_string( $handle ) ) {
			return $this->refuse( 'Code entry continued without a code request in the session' );
		}

		// Shown in groups in the mail, so a member who copied it brings the spaces along.
		$result = $this->codeVerification->verify(
			$handle,
			DisplayedCode::ungrouped( $request->memberaccessCode )
		);

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
	 *
	 * The allowlist is asked whatever the route admits, since a matching entry is what attributes a
	 * member to a group. On an open route, an address no entry matches is admitted without one.
	 */
	private function admit( string $verifiedAddress ): AuthenticationResponse {
		$email = NormalizedEmail::fromString( $verifiedAddress );
		$group = $email === null ? null : $this->matcher->match( $email );

		if ( $email === null || !$this->mode->admits( $group ) ) {
			$this->auditLogger->info( 'Proven address is not admitted', [
				'email' => NormalizedEmail::hashOf( $verifiedAddress )
			] );

			return AuthenticationResponse::newFail( wfMessage( 'memberaccess-auth-not-authorized' ) );
		}

		$username = $this->usernameFor( $email );

		if ( $username === null ) {
			return $this->refuse( 'Proven address cannot be used as a username', $email );
		}

		$account = $this->registeredAccountNamed( $username );

		if ( $account !== null ) {
			$member = $this->members->getMember( $account->getId(), ReadConsistency::UpToDate );

			if ( $member?->email !== $email->value ) {
				return $this->refuse( 'Derived username belongs to another account', $email );
			}

			$this->attributeToGroup( $member, $group );
		}

		$this->manager->setAuthenticationSessionData(
			self::PROVISIONING_SESSION_KEY,
			( new PendingProvisioning( username: $username, email: $email, groupId: $group?->id ) )->toSessionData()
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
	 * The account the username already names, if any. Read as recently as the account itself was
	 * written, or a member provisioned moments ago looks like somebody else's account and is
	 * turned away.
	 *
	 * An address proves a mailbox, never an account that was made some other way, so what makes
	 * such an account this member's is the roster saying the two belong together.
	 */
	private function registeredAccountNamed( string $username ): ?UserIdentity {
		$user = $this->userLookup->getUserIdentityByName( $username, IDBAccessObject::READ_LATEST );

		return $user !== null && $user->isRegistered() ? $user : null;
	}

	/**
	 * A member the open route admitted has no group until an allowlist entry matches them. Their
	 * login is where that group is written down, since it is what the roster shows them under and
	 * what the per-group counts add up.
	 *
	 * That a group already given is never moved is the repository's rule, held in the condition it
	 * writes under. Asking here as well is what keeps an ordinary login from writing at all.
	 */
	private function attributeToGroup( Member $member, ?MemberGroup $group ): void {
		if ( $group !== null && $member->groupId === null ) {
			$this->members->attributeToGroup( userId: $member->userId, groupId: $group->id );
		}
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

	/**
	 * Asked of every account a login or a password change names, whatever the route is set to, so a
	 * wiki without a roster answers that it has no members rather than reaching for the table.
	 */
	public function testUserExists( $username, $flags = IDBAccessObject::READ_NORMAL ) {
		if ( $this->schema->isMissing() ) {
			return false;
		}

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
		if ( $this->isPasswordRequest( $req ) && $this->isRefusedAPassword( $req->username ) ) {
			return StatusValue::newFatal( 'memberaccess-auth-password-refused' );
		}

		return StatusValue::newGood( 'ignored' );
	}

	private function isPasswordRequest( AuthenticationRequest $req ): bool {
		return $req instanceof PasswordAuthenticationRequest
			|| $req instanceof TemporaryPasswordAuthenticationRequest;
	}

	/**
	 * The roster says who the members are. Where it cannot be read, the reader group says it
	 * instead: provisioning puts it on a member's account before the roster row, so every member
	 * carries it, and refusing them a password is the rule that must not lapse while the tables are
	 * away. It is the wider answer of the two — an account can be put in the group by hand — which
	 * is the way round to err while the roster cannot say which accounts those are.
	 */
	private function isRefusedAPassword( ?string $username ): bool {
		$canonical = $username === null
			? false
			: $this->userNameUtils->getCanonical( $username, UserRigorOptions::RIGOR_USABLE );

		if ( $canonical === false ) {
			return false;
		}

		if ( $this->schema->isMissing() ) {
			return $this->accountNamedHoldsTheReaderGroup( $canonical );
		}

		return $this->testUserExists( $canonical, IDBAccessObject::READ_LATEST );
	}

	/**
	 * Both reads are as recent as the account and its group can have been written, the way the
	 * roster is read on the other branch: a group given moments ago that read as absent would let a
	 * password through.
	 */
	private function accountNamedHoldsTheReaderGroup( string $username ): bool {
		$user = $this->userLookup->getUserIdentityByName( $username, IDBAccessObject::READ_LATEST );

		return $user !== null && $user->isRegistered() && $this->holdsTheReaderGroup( $user );
	}

	private function holdsTheReaderGroup( UserIdentity $user ): bool {
		return in_array(
			$this->readerGroup,
			$this->userGroups->getUserGroups( $user, IDBAccessObject::READ_LATEST ),
			true
		);
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
