<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Tests\TestDoubles;

use Psr\Log\AbstractLogger;
use Stringable;

class SpyLogger extends AbstractLogger {

	/**
	 * @var array<int, array{level: string, entry: string}>
	 */
	private array $records = [];

	/**
	 * @param mixed $level
	 * @param string|Stringable $message
	 * @param mixed[] $context
	 */
	public function log( $level, $message, array $context = [] ): void {
		$this->records[] = [
			'level' => is_scalar( $level ) ? strval( $level ) : '',
			'entry' => (string)$message . ' ' . json_encode( $context )
		];
	}

	/**
	 * @return string[]
	 */
	public function getEntries(): array {
		return array_column( $this->records, 'entry' );
	}

	/**
	 * @return string[]
	 */
	public function getEntriesAtLevel( string $level ): array {
		return array_column( array_filter(
			$this->records,
			static fn ( array $record ): bool => $record['level'] === $level
		), 'entry' );
	}

	public function getLog(): string {
		return implode( "\n", $this->getEntries() );
	}

}
