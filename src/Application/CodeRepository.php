<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

interface CodeRepository {

	public function store( string $handle, IssuedCode $code, int $ttlInSeconds ): void;

	/**
	 * @return ?IssuedCode Null when the handle is unknown or its code has expired
	 */
	public function get( string $handle ): ?IssuedCode;

	public function delete( string $handle ): void;

}
