<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\SpecialPage\Hook\AuthChangeFormFieldsHook;
use ProfessionalWiki\MemberAccess\EntryPoints\Auth\LoginCodeRequest;
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
