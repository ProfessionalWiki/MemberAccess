<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\REST;

use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Session\CsrfTokenSet;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Base class for the /member-access/v0/ endpoints.
 *
 * Every endpoint requires the memberaccess-manage right. Writes additionally require the wiki's
 * CSRF token in an X-CSRF-TOKEN header, unless the session provider is already safe against
 * forgery, since the admin panel calls these with the session cookie. Reads skip the token: the
 * same-origin policy already keeps another site from reading the response.
 *
 * Failures answer with a stable errorCode alongside the human readable message, so that the caller
 * can act on the reason rather than parse prose.
 */
abstract class MemberAccessApiHandler extends SimpleHandler {

	public const string MANAGE_RIGHT = 'memberaccess-manage';

	private const string CSRF_HEADER = 'X-CSRF-TOKEN';

	public function __construct(
		private readonly CsrfTokenSet $csrfTokens
	) {
	}

	public function execute() {
		$refusal = $this->refuse();

		return $refusal ?? parent::execute();
	}

	private function refuse(): ?Response {
		if ( !$this->getAuthority()->getUser()->isRegistered() ) {
			return $this->newErrorResponse( 'not_logged_in', 'This endpoint requires logging in', 401 );
		}

		if ( !$this->getAuthority()->isAllowed( self::MANAGE_RIGHT ) ) {
			return $this->newErrorResponse(
				'permission_denied',
				'This endpoint requires the "' . self::MANAGE_RIGHT . '" right',
				403
			);
		}

		if ( $this->changesState() && !$this->hasValidCsrfToken() ) {
			return $this->newErrorResponse( 'invalid_csrf_token', 'This endpoint requires a CSRF token', 403 );
		}

		return null;
	}

	private function changesState(): bool {
		return $this->getRequest()->getMethod() !== 'GET';
	}

	private function hasValidCsrfToken(): bool {
		if ( $this->getSession()->getProvider()->safeAgainstCsrf() ) {
			return true;
		}

		$token = $this->getRequest()->getHeader( self::CSRF_HEADER )[0] ?? '';

		return $token !== '' && $this->csrfTokens->matchToken( $token );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	protected function newJsonResponse( array $data ): Response {
		return $this->getResponseFactory()->createJson( $data );
	}

	/**
	 * @param array<string, mixed> $extra Fields the caller needs to act on the failure
	 */
	protected function newErrorResponse(
		string $errorCode,
		string $message,
		int $status,
		array $extra = []
	): Response {
		$response = $this->getResponseFactory()->createJson(
			[ 'errorCode' => $errorCode, 'error' => $message ] + $extra
		);
		$response->setStatus( $status );

		return $response;
	}

	/**
	 * A body that is not a JSON object never reaches a handler: the REST framework answers that
	 * itself, with its own error shape.
	 *
	 * @return array<mixed>
	 */
	protected function bodyData(): array {
		$body = $this->getRequest()->getParsedBody();

		return is_array( $body ) ? $body : [];
	}

	protected function bodyString( string $field ): string {
		$value = $this->bodyData()[$field] ?? null;

		return is_string( $value ) ? $value : '';
	}

	protected static function toIso8601( ?string $timestamp ): ?string {
		if ( $timestamp === null ) {
			return null;
		}

		$converted = ConvertibleTimestamp::convert( TS_ISO_8601, $timestamp );

		return $converted === false ? null : $converted;
	}

	protected function performerId(): int {
		return $this->getAuthority()->getUser()->getId();
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected static function idPathParam( string $name ): array {
		return [
			$name => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true
			]
		];
	}

}
