<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

/**
 * Bounds how often codes can be requested, per address and per client IP, with a burst limit over
 * fifteen minutes and a limit over a day. Counters rise on every request, refused ones included,
 * so repeated requests cannot be used to enumerate the allowlist.
 */
class RequestThrottle {

	private const int BURST_WINDOW_SECONDS = 900;
	private const int DAY_IN_SECONDS = 86400;

	public function __construct(
		private readonly CounterStore $counters,
		private readonly int $emailBurstLimit,
		private readonly int $emailDailyLimit,
		private readonly int $ipBurstLimit,
		private readonly int $ipDailyLimit
	) {
	}

	public function recordRequest( NormalizedEmail $email, string $clientIp ): bool {
		$withinLimits = [
			$this->hit( 'email-burst:' . $email->hash(), self::BURST_WINDOW_SECONDS, $this->emailBurstLimit ),
			$this->hit( 'email-day:' . $email->hash(), self::DAY_IN_SECONDS, $this->emailDailyLimit ),
			$this->hit( 'ip-burst:' . $clientIp, self::BURST_WINDOW_SECONDS, $this->ipBurstLimit ),
			$this->hit( 'ip-day:' . $clientIp, self::DAY_IN_SECONDS, $this->ipDailyLimit )
		];

		return !in_array( false, $withinLimits, true );
	}

	/**
	 * A counter that cannot be raised refuses the request: letting it through while nothing counts
	 * would take the limit off altogether.
	 */
	private function hit( string $key, int $windowInSeconds, int $limit ): bool {
		$count = $this->counters->increment( $key, $windowInSeconds );

		return $count !== null && $count <= $limit;
	}

}
