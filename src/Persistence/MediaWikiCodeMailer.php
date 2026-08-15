<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use MailAddress;
use MediaWiki\Mail\IEmailer;
use ProfessionalWiki\MemberAccess\Application\CodeMailer;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use Psr\Log\LoggerInterface;

class MediaWikiCodeMailer implements CodeMailer {

	public function __construct(
		private readonly IEmailer $emailer,
		private readonly MailAddress $sender,
		private readonly LoggerInterface $logger
	) {
	}

	public function sendCode( NormalizedEmail $email, string $code, int $expiryInMinutes ): void {
		$status = $this->emailer->send(
			to: new MailAddress( $email->value ),
			from: $this->sender,
			subject: wfMessage( 'memberaccess-code-email-subject' )->inContentLanguage()->text(),
			bodyText: wfMessage( 'memberaccess-code-email-body' )
				->params( $code, $expiryInMinutes )
				->inContentLanguage()
				->text()
		);

		if ( !$status->isGood() ) {
			$this->logger->warning( 'Sending a login code failed', [
				'email' => $email->hash(),
				'status' => $status->__toString()
			] );
		}
	}

}
