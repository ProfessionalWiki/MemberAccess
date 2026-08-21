<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Html\Html;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\SpecialPage\Hook\AuthChangeFormFieldsHook;
use MediaWiki\SpecialPage\SpecialPage;
use ProfessionalWiki\MemberAccess\Application\RandomSecretGenerator;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\EnterCodeRequest;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\LoginCodeRequest;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\ResendCodeRequest;
use Skin;

/**
 * Sets the member route apart on the login form. Described fields are laid out in provider order,
 * which puts the code button above the button it is an alternative to.
 */
class LoginFormHandler implements AuthChangeFormFieldsHook, BeforePageDisplayHook {

	/** Core gives the log in button weight 100 and the help link below it 200. */
	private const DIVIDER_WEIGHT = 105;
	private const EMAIL_WEIGHT = 106;
	private const BUTTON_WEIGHT = 107;
	private const RESEND_PROMPT_WEIGHT = 110;
	private const RESEND_WEIGHT = 111;
	private const RESTART_WEIGHT = 120;

	/** Entering the code is the way on from that screen; what is offered beside it reads as a link. */
	private const INLINE_ACTION_CLASS = 'mw-memberaccess-inline-action';
	private const FALLBACK_CLASS = 'mw-memberaccess-fallback';
	/** The field the press is collected from, which the sentence above draws its own button for. */
	private const COLLECTOR_CLASS = 'mw-memberaccess-collector';

	/** @var array<string, int> */
	private const CAPTCHA_WEIGHTS = [
		'captchaInfo' => 108,
		'captchaWord' => 109
	];

	/**
	 * @param AuthenticationRequest[] $requests
	 * @param array<string, mixed> $fieldInfo
	 * @param array<string, mixed> &$formDescriptor
	 * @param string $action
	 */
	public function onAuthChangeFormFields( $requests, $fieldInfo, &$formDescriptor, $action ): void {
		if ( $action === AuthManager::ACTION_LOGIN_CONTINUE ) {
			if ( isset( $formDescriptor[EnterCodeRequest::CODE_FIELD] ) ) {
				$this->describeTheCodeBox( $formDescriptor );
				$this->offerTheFallbacks( $formDescriptor, $requests );
			}

			return;
		}

		if ( $action !== AuthManager::ACTION_LOGIN || !isset( $formDescriptor[LoginCodeRequest::EMAIL_FIELD] ) ) {
			return;
		}

		// An address is what the box takes, so it says so: the keyboard a phone offers for it, and
		// what the browser fills it from. AuthenticationRequest has no type for that, describing
		// every text box as a string, so it is put on here.
		$formDescriptor[LoginCodeRequest::EMAIL_FIELD] = array_merge(
			$this->fieldAt( $formDescriptor, LoginCodeRequest::EMAIL_FIELD ),
			[
				'type' => 'email',
				'placeholder-message' => 'memberaccess-auth-email-placeholder',
				'autocomplete' => 'email',
				'cssclass' => 'mw-memberaccess-email',
				'weight' => self::EMAIL_WEIGHT
			]
		);

		// Progressive without being primary: the log in button above already is.
		$formDescriptor[LoginCodeRequest::BUTTON_NAME] = array_merge(
			$this->fieldAt( $formDescriptor, LoginCodeRequest::BUTTON_NAME ),
			[
				'flags' => [ 'progressive' ],
				'weight' => self::BUTTON_WEIGHT
			]
		);

		$formDescriptor['memberaccessDivider'] = [
			'type' => 'info',
			'cssclass' => 'mw-memberaccess-divider',
			'default' => wfMessage( 'memberaccess-auth-form-divider' )->text(),
			'weight' => self::DIVIDER_WEIGHT
		];

		$this->moveTheCaptchaBelowBothRoutes( $formDescriptor );
	}

	/**
	 * A captcha refuses the attempt whatever button was pressed: ConfirmEdit counts bad logins per
	 * client IP, and either route fills that counter. Described it sits inside the password form,
	 * where it reads as that route's alone; below both it is next to neither.
	 *
	 * The fields are ConfirmEdit's, so one under another name is left where it puts itself.
	 *
	 * @param array<string, mixed> &$formDescriptor
	 */
	private function moveTheCaptchaBelowBothRoutes( array &$formDescriptor ): void {
		foreach ( self::CAPTCHA_WEIGHTS as $field => $weight ) {
			if ( isset( $formDescriptor[$field] ) ) {
				$formDescriptor[$field] = array_merge(
					$this->fieldAt( $formDescriptor, $field ),
					[ 'weight' => $weight ]
				);
			}
		}
	}

	/**
	 * A one-time code, said in the terms a browser knows: what to offer from the mail it just
	 * arrived in, and how much of it there is. `inputmode` would say which keyboard to raise for it,
	 * but HTMLForm passes no such attribute through, so that much is left unsaid.
	 *
	 * @param array<string, mixed> &$formDescriptor
	 */
	private function describeTheCodeBox( array &$formDescriptor ): void {
		$formDescriptor[EnterCodeRequest::CODE_FIELD] = array_merge(
			$this->fieldAt( $formDescriptor, EnterCodeRequest::CODE_FIELD ),
			[
				'autocomplete' => 'one-time-code',
				'placeholder-message' => 'memberaccess-auth-code-placeholder',
				'maxlength' => RandomSecretGenerator::CODE_DIGITS,
				'pattern' => '[0-9]{' . RandomSecretGenerator::CODE_DIGITS . '}'
			]
		);
	}

	/**
	 * What the visitor does when the code does not arrive, or when they see they typed the address
	 * wrongly. Both are one sentence naming the symptom with the action inside it, so that entering
	 * the code stays the one thing the screen asks for.
	 *
	 * The sentence is one piece of markup rather than a prompt field beside a button field: a form
	 * field is laid out as a block within a block, and no amount of asking makes two of them read as
	 * one line. The button in it is real, but the field it belongs to is what the form collects the
	 * press from, so that field stays described and is hidden instead of removed.
	 * {@see \MediaWiki\SpecialPage\AuthManagerSpecialPage::handleFormSubmit}
	 *
	 * Once the throttle will send no more, the sentence says so and carries no button, and the field
	 * goes with it: an action that cannot be taken is not shown at all.
	 *
	 * @param array<string, mixed> &$formDescriptor
	 * @param AuthenticationRequest[] $requests
	 */
	private function offerTheFallbacks( array &$formDescriptor, array $requests ): void {
		$resendIsAvailable = $this->resendIsAvailable( $formDescriptor, $requests );

		if ( $resendIsAvailable ) {
			$formDescriptor[ResendCodeRequest::BUTTON_NAME] = array_merge(
				$this->fieldAt( $formDescriptor, ResendCodeRequest::BUTTON_NAME ),
				[ 'cssclass' => self::COLLECTOR_CLASS, 'weight' => self::RESEND_WEIGHT ]
			);
		} else {
			unset( $formDescriptor[ResendCodeRequest::BUTTON_NAME] );
		}

		$formDescriptor['memberaccessResendLine'] = $this->fallbackLine(
			$resendIsAvailable
				? wfMessage( 'memberaccess-auth-resend-line' )->rawParams( $this->resendButton() )->escaped()
				: wfMessage( 'memberaccess-auth-resend-throttled' )->escaped(),
			self::RESEND_PROMPT_WEIGHT
		);

		$formDescriptor['memberaccessRestartLine'] = $this->fallbackLine(
			wfMessage( 'memberaccess-auth-restart-line' )->rawParams( $this->restartLink() )->escaped(),
			self::RESTART_WEIGHT
		);
	}

	/**
	 * A screen that offers no resend request is a screen with no resend to offer, which is what a
	 * session begun before this button existed looks like. Read the other way round, the form would
	 * be told to describe a field it was never given, and refuse to draw itself at all.
	 *
	 * @param array<string, mixed> $formDescriptor
	 * @param AuthenticationRequest[] $requests
	 */
	private function resendIsAvailable( array $formDescriptor, array $requests ): bool {
		$request = AuthenticationRequest::getRequestByClass( $requests, ResendCodeRequest::class );

		return $request instanceof ResendCodeRequest
			&& $request->isAvailable()
			&& isset( $formDescriptor[ResendCodeRequest::BUTTON_NAME] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fallbackLine( string $html, int $weight ): array {
		return [
			'type' => 'info',
			'cssclass' => self::FALLBACK_CLASS,
			'raw' => true,
			'default' => $html,
			'weight' => $weight
		];
	}

	private function resendButton(): string {
		return Html::element(
			'button',
			[
				'type' => 'submit',
				'name' => ResendCodeRequest::BUTTON_NAME,
				'value' => '1',
				'formnovalidate' => true,
				'class' => self::INLINE_ACTION_CLASS
			],
			wfMessage( 'memberaccess-auth-resend-label' )->text()
		);
	}

	/**
	 * Starting the login form again is what changes the address a code was asked for, and it already
	 * does: the form asks for an address again rather than resuming the code screen.
	 */
	private function restartLink(): string {
		return Html::element(
			'a',
			[
				'href' => SpecialPage::getTitleFor( 'Userlogin' )->getLocalURL(),
				'class' => self::INLINE_ACTION_CLASS
			],
			wfMessage( 'memberaccess-auth-restart' )->text()
		);
	}

	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( $out->getTitle()?->isSpecial( 'Userlogin' ) === true ) {
			$out->addModuleStyles( 'ext.memberAccess.loginForm' );
		}
	}

	/**
	 * @param array<string, mixed> $formDescriptor
	 * @return array<string, mixed>
	 */
	private function fieldAt( array $formDescriptor, string $name ): array {
		$field = $formDescriptor[$name] ?? [];

		return is_array( $field ) ? $field : [];
	}

}
