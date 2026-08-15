<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

use Psr\Log\LoggerInterface;

/**
 * Issues a login code, answering the same way whether or not the address is on the allowlist.
 *
 * A code is stored for every accepted request, including for addresses that are not admitted: the
 * mail is what differs, not the state. Skipping the store for unlisted addresses would let anyone
 * tell the two apart by entering one wrong code, since only a stored code can fail retryably.
 *
 * A deactivated member is treated exactly like an unlisted address, so that whether someone was
 * once a member here cannot be read off the response either.
 */
class RequestCodeUseCase {

	public function __construct(
		private readonly AllowlistMatcher $matcher,
		private readonly MemberRepository $members,
		private readonly RequestThrottle $throttle,
		private readonly SecretGenerator $generator,
		private readonly CodeHasher $hasher,
		private readonly CodeRepository $codes,
		private readonly CodeMailer $mailer,
		private readonly LoggerInterface $logger,
		private readonly CodeLifetime $codeLifetime
	) {
	}

	public function requestCode( string $emailInput, string $clientIp ): CodeRequestResult {
		$email = NormalizedEmail::fromString( $emailInput );

		if ( $email === null ) {
			return CodeRequestResult::invalidEmail();
		}

		if ( !$this->throttle->recordRequest( $email, $clientIp ) ) {
			$this->logger->info( 'Login code request refused by the throttle', [ 'email' => $email->hash() ] );

			return CodeRequestResult::throttled();
		}

		return CodeRequestResult::accepted( $this->issueCode( $email ) );
	}

	private function issueCode( NormalizedEmail $email ): string {
		$handle = $this->generator->generateHandle();
		$code = $this->generator->generateCode();

		$this->codes->store(
			handle: $handle,
			code: new IssuedCode( email: $email->value, codeHash: $this->hasher->hash( $code ) ),
			ttlInSeconds: $this->codeLifetime->inSeconds
		);

		// Both are asked whatever the answer to either, so that the work done is the same for an
		// admitted address, an unlisted one and a deactivated member.
		$group = $this->matcher->match( $email );
		$member = $this->members->findMemberByEmail( $email );

		if ( $group === null ) {
			$this->logger->info( 'Login code requested for an address that is not admitted', [
				'email' => $email->hash()
			] );

			return $handle;
		}

		if ( $member !== null && !$member->isActive() ) {
			$this->logger->info( 'Login code requested by a deactivated member', [
				'email' => $email->hash()
			] );

			return $handle;
		}

		$this->mailer->sendCode( $email, $code, $this->codeLifetime->inMinutes() );

		$this->logger->info( 'Login code issued', [
			'email' => $email->hash(),
			'group' => $group->id
		] );

		return $handle;
	}

}
