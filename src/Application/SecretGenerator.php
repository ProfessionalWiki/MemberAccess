<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

interface SecretGenerator {

	/**
	 * The code the member types in, short enough to be read from an email.
	 */
	public function generateCode(): string;

	/**
	 * The opaque handle an issued code is stored under, long enough to be unguessable.
	 */
	public function generateHandle(): string;

}
