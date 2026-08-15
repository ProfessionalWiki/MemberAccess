<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use MailAddress;
use MediaWiki\Mail\IEmailer;
use StatusValue;

class SpyEmailer implements IEmailer {

	/**
	 * @var array<int, array{to: string, from: string, subject: string, bodyText: string}>
	 */
	private array $sentMails = [];

	public function __construct(
		private readonly bool $sendSucceeds = true
	) {
	}

	/**
	 * @param MailAddress|MailAddress[] $to
	 * @param mixed[] $options
	 */
	public function send(
		$to,
		MailAddress $from,
		string $subject,
		string $bodyText,
		?string $bodyHtml = null,
		array $options = []
	): StatusValue {
		$recipients = is_array( $to ) ? $to : [ $to ];

		$this->sentMails[] = [
			'to' => implode( ', ', array_map( static fn ( MailAddress $address ): string => $address->address, $recipients ) ),
			'from' => $from->address,
			'subject' => $subject,
			'bodyText' => $bodyText
		];

		return $this->sendSucceeds ? StatusValue::newGood() : StatusValue::newFatal( 'mail-failed' );
	}

	/**
	 * @return array<int, array{to: string, from: string, subject: string, bodyText: string}>
	 */
	public function getSentMails(): array {
		return $this->sentMails;
	}

}
