<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use ProfessionalWiki\MemberAccess\Application\CodeMailer;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;

class SpyCodeMailer implements CodeMailer {

	/**
	 * @var array<int, array{email: string, code: string, expiryInMinutes: int}>
	 */
	private array $sentMails = [];

	public function sendCode( NormalizedEmail $email, string $code, int $expiryInMinutes ): void {
		$this->sentMails[] = [
			'email' => $email->value,
			'code' => $code,
			'expiryInMinutes' => $expiryInMinutes
		];
	}

	/**
	 * @return array<int, array{email: string, code: string, expiryInMinutes: int}>
	 */
	public function getSentMails(): array {
		return $this->sentMails;
	}

}
