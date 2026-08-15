<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use ProfessionalWiki\MemberAccess\Application\CounterStore;
use Psr\Log\LoggerInterface;
use Wikimedia\ObjectCache\BagOStuff;

class StashCounterStore implements CounterStore {

	public function __construct(
		private readonly BagOStuff $stash,
		private readonly LoggerInterface $logger
	) {
	}

	public function increment( string $key, int $ttlInSeconds ): ?int {
		$count = $this->stash->incrWithInit(
			$this->stash->makeKey( 'memberaccess', 'counter', $key ),
			$ttlInSeconds
		);

		if ( !is_int( $count ) ) {
			$this->logger->error( 'A counter could not be raised: the object stash is unavailable' );

			return null;
		}

		return $count;
	}

}
