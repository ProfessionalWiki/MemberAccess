<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use MailAddress;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Language\Language;
use MediaWiki\Mail\IEmailer;
use ProfessionalWiki\MemberAccess\Application\CodeMailer;
use ProfessionalWiki\MemberAccess\Application\DisplayedCode;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Message\MessageSpecifier;

class MediaWikiCodeMailer implements CodeMailer {

	private const string TEMPLATE = 'CodeEmail';

	public function __construct(
		private readonly IEmailer $emailer,
		private readonly MailAddress $sender,
		private readonly TemplateParser $templates,
		private readonly Language $contentLanguage,
		private readonly string $siteName,
		private readonly LoggerInterface $logger
	) {
	}

	/**
	 * Sent as both a formatted part and a plain one. Which of them a member sees is their mail
	 * client's to decide, and a wiki that has not turned formatted mail on sends only the second, so
	 * it carries the same code and the same warning rather than a note to read the mail elsewhere.
	 */
	public function sendCode( NormalizedEmail $email, string $code, int $expiryInMinutes ): void {
		$displayed = DisplayedCode::grouped( $code );

		$status = $this->emailer->send(
			to: new MailAddress( $email->value ),
			from: $this->sender,
			subject: $this->text( 'memberaccess-code-email-subject' ),
			bodyText: $this->text( 'memberaccess-code-email-body', $displayed, $expiryInMinutes ),
			bodyHtml: $this->templates->processTemplate( self::TEMPLATE, [
				// The same name the plain part gets from {{SITENAME}}, so the two cannot disagree.
				'siteName' => $this->siteName,
				'intro' => $this->text( 'memberaccess-code-email-intro' ),
				'code' => $displayed,
				'validity' => $this->text( 'memberaccess-code-email-validity', $expiryInMinutes ),
				'disclaimer' => $this->text( 'memberaccess-code-email-disclaimer' ),
				'lang' => $this->contentLanguage->getHtmlCode(),
				'dir' => $this->contentLanguage->getDir()
			] )
		);

		if ( !$status->isGood() ) {
			$this->logger->warning( 'Sending a login code failed', [
				'email' => $email->hash(),
				'reasons' => self::reasonsFor( $status )
			] );
		}
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
	 * In the wiki's own language, since who the mail is going to is exactly what has not been
	 * established yet.
	 *
	 * A message is preprocessed as wikitext before it is put together, so anything substituted into
	 * one as an ordinary parameter is preprocessed with it. Only a count is passed that way, because
	 * PLURAL has to be able to read it; everything else goes in afterwards, out of the preprocessor's
	 * reach. Nothing given here is a visitor's to write, and this is what keeps it that way should
	 * something one day be. {@see \MediaWiki\Message\Message::transformText}
	 */
	private function text( string $key, string|int ...$params ): string {
		$message = wfMessage( $key );

		foreach ( $params as $param ) {
			if ( is_int( $param ) ) {
				$message->numParams( $param );
			} else {
				$message->plaintextParams( $param );
			}
		}

		return $message->inContentLanguage()->text();
	}

}
