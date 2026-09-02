<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use MailAddress;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Language\Language;
use MediaWiki\Mail\IEmailer;
use ProfessionalWiki\MemberAccess\Application\InvitationMailer;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Message\MessageSpecifier;

class MediaWikiInvitationMailer implements InvitationMailer {

	private const string TEMPLATE = 'InvitationEmail';

	public function __construct(
		private readonly IEmailer $emailer,
		private readonly MailAddress $sender,
		private readonly TemplateParser $templates,
		private readonly Language $contentLanguage,
		private readonly string $siteName,
		private readonly string $loginUrl,
		private readonly LoggerInterface $logger
	) {
	}

	/**
	 * Sent as both a formatted part and a plain one. Which of them the recipient sees is their mail
	 * client's to decide, and a wiki that has not turned formatted mail on sends only the second, so
	 * it carries the same way in and the same address to log in with rather than a note to read the
	 * mail elsewhere.
	 */
	public function sendInvitation( NormalizedEmail $email ): bool {
		$status = $this->emailer->send(
			to: new MailAddress( $email->value ),
			from: $this->sender,
			subject: $this->text( 'memberaccess-invitation-email-subject' ),
			bodyText: $this->text( 'memberaccess-invitation-email-body', $this->loginUrl, $email->value ),
			bodyHtml: $this->templates->processTemplate( self::TEMPLATE, [
				// The same name the plain part gets from {{SITENAME}}, so the two cannot disagree.
				'siteName' => $this->siteName,
				'intro' => $this->text( 'memberaccess-invitation-email-intro' ),
				'loginUrl' => $this->loginUrl,
				'instructions' => $this->text( 'memberaccess-invitation-email-instructions', $email->value ),
				'disclaimer' => $this->text( 'memberaccess-invitation-email-disclaimer' ),
				'lang' => $this->contentLanguage->getHtmlCode(),
				'dir' => $this->contentLanguage->getDir()
			] )
		);

		if ( !$status->isGood() ) {
			$this->logger->warning( 'Sending an invitation failed', [
				'email' => $email->hash(),
				'reasons' => self::reasonsFor( $status )
			] );

			return false;
		}

		return true;
	}

	/**
	 * Why the mail was refused, as message keys alone. A rendered status carries whatever the mail
	 * transport said, and a server refusing an address usually says it back, which would put the
	 * address in the log the hash above keeps it out of.
	 *
	 * @return string[]
	 */
	private static function reasonsFor( StatusValue $status ): array {
		return array_map(
			static fn ( MessageSpecifier $message ): string => $message->getKey(),
			$status->getMessages()
		);
	}

	/**
	 * In the wiki's own language: an invitation goes to an address rather than to an account, so
	 * there is nobody whose language preference could be read.
	 *
	 * A message is preprocessed as wikitext before it is put together, so anything substituted into
	 * one as an ordinary parameter is preprocessed with it. Everything here goes in afterwards, out
	 * of the preprocessor's reach, since neither the address nor the wiki's own URL is anything the
	 * preprocessor should be reading. {@see \MediaWiki\Message\Message::transformText}
	 */
	private function text( string $key, string ...$params ): string {
		return wfMessage( $key )->plaintextParams( ...$params )->inContentLanguage()->text();
	}

}
