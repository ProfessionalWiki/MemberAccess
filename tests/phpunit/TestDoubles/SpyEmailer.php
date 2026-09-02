<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use MailAddress;
use MediaWiki\Mail\IEmailer;
use StatusValue;

class SpyEmailer implements IEmailer {

	/**
	 * @var array<int, array{to: string, from: string, subject: string, bodyText: string, bodyHtml: ?string}>
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
			'bodyText' => $bodyText,
			'bodyHtml' => $bodyHtml
		];

		return $this->sendSucceeds ? StatusValue::newGood() : self::newRefusal( $recipients[0] );
	}

	/**
	 * A refusal the way a mail server gives one: quoting back the address it would not deliver to.
	 * Anything putting a rendered status in a log puts that address there with it.
	 */
	private static function newRefusal( MailAddress $recipient ): StatusValue {
		return StatusValue::newFatal( 'pear-mail-error', '550 5.1.1 <' . $recipient->address . '>: unknown user' );
	}

	/**
	 * @return array<int, array{to: string, from: string, subject: string, bodyText: string, bodyHtml: ?string}>
	 */
	public function getSentMails(): array {
		return $this->sentMails;
	}

}
