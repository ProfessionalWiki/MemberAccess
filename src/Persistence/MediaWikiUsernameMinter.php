<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserNameUtils;
use MediaWiki\User\UserRigorOptions;
use ProfessionalWiki\MemberAccess\Application\UsernameGenerator;
use ProfessionalWiki\MemberAccess\Application\UsernameMinter;
use RuntimeException;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Draws names until one is free, since a name is drawn without looking at what is already taken.
 *
 * A name being free is read from the primary database: the account it is for is created moments
 * later, and two minted moments apart must not arrive at one name because a replica had not caught
 * up with the first.
 */
class MediaWikiUsernameMinter implements UsernameMinter {

	/**
	 * A billion names against a roster of thousands, so the first draw is free unless something is
	 * wrong. Enough draws to make a coincidence harmless, few enough that a generator handing out
	 * one name over and over gives up rather than spins.
	 */
	private const int DRAWS = 10;

	public function __construct(
		private readonly UsernameGenerator $generator,
		private readonly UserNameUtils $userNameUtils,
		private readonly UserIdentityLookup $userLookup
	) {
	}

	public function mintUsername(): string {
		for ( $draw = 1; $draw <= self::DRAWS; $draw++ ) {
			$name = $this->creatableForm( $this->generator->generateUsername() );

			if ( $name !== null && !$this->isTaken( $name ) ) {
				return $name;
			}
		}

		throw new RuntimeException( 'No free username could be minted for a member account' );
	}

	/**
	 * The name in the form an account is created under, which is also the form the account will be
	 * found by afterwards. A name MediaWiki would refuse an account under is no name at all here.
	 */
	private function creatableForm( string $candidate ): ?string {
		$canonical = $this->userNameUtils->getCanonical( $candidate, UserRigorOptions::RIGOR_CREATABLE );

		return $canonical === false ? null : $canonical;
	}

	private function isTaken( string $name ): bool {
		$account = $this->userLookup->getUserIdentityByName( $name, IDBAccessObject::READ_LATEST );

		return $account !== null && $account->isRegistered();
	}

}
