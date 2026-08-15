<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use ProfessionalWiki\MemberAccess\Application\CodeRepository;
use ProfessionalWiki\MemberAccess\Application\IssuedCode;
use Wikimedia\ObjectCache\BagOStuff;

/**
 * Keeps issued codes in the main object stash, which expires them on its own and, unlike the main
 * cache, is backed by real storage on every deployment.
 */
class StashCodeRepository implements CodeRepository {

	private const string EMAIL_FIELD = 'email';
	private const string HASH_FIELD = 'hash';

	public function __construct(
		private readonly BagOStuff $stash
	) {
	}

	public function store( string $handle, IssuedCode $code, int $ttlInSeconds ): void {
		$this->stash->set(
			$this->key( $handle ),
			[
				self::EMAIL_FIELD => $code->email,
				self::HASH_FIELD => $code->codeHash
			],
			$ttlInSeconds
		);
	}

	public function get( string $handle ): ?IssuedCode {
		$stored = $this->stash->get( $this->key( $handle ) );

		if ( !is_array( $stored ) ) {
			return null;
		}

		$email = $stored[self::EMAIL_FIELD] ?? null;
		$hash = $stored[self::HASH_FIELD] ?? null;

		if ( !is_string( $email ) || !is_string( $hash ) ) {
			return null;
		}

		return new IssuedCode( email: $email, codeHash: $hash );
	}

	public function delete( string $handle ): void {
		$this->stash->delete( $this->key( $handle ) );
	}

	private function key( string $handle ): string {
		return $this->stash->makeKey( 'memberaccess', 'code', $handle );
	}

}
