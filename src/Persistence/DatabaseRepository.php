<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Shared plumbing for the database adapters: connections, timestamps and reading untyped row values.
 */
abstract class DatabaseRepository {

	protected const string GROUP_TABLE = 'memberaccess_group';
	protected const string ENTRY_TABLE = 'memberaccess_entry';
	protected const string MEMBER_TABLE = 'memberaccess_member';

	public function __construct(
		protected readonly IConnectionProvider $connectionProvider
	) {
	}

	protected function databaseFor( ReadConsistency $consistency ): IReadableDatabase {
		return $consistency === ReadConsistency::UpToDate
			? $this->connectionProvider->getPrimaryDatabase()
			: $this->connectionProvider->getReplicaDatabase();
	}

	protected function now(): string {
		return ConvertibleTimestamp::now( TS_MW );
	}

	protected function asInt( mixed $value ): int {
		return is_scalar( $value ) ? intval( $value ) : 0;
	}

	protected function asOptionalInt( mixed $value ): ?int {
		return $value === null ? null : $this->asInt( $value );
	}

	protected function asString( mixed $value ): string {
		return is_scalar( $value ) ? strval( $value ) : '';
	}

	protected function asTimestamp( mixed $value ): string {
		$timestamp = ConvertibleTimestamp::convert( TS_MW, $this->asString( $value ) );

		return $timestamp === false ? '' : $timestamp;
	}

	protected function asOptionalTimestamp( mixed $value ): ?string {
		return $value === null ? null : $this->asTimestamp( $value );
	}

	/**
	 * @return stdClass[]
	 */
	protected function toRows( IResultWrapper $result ): array {
		$rows = [];

		foreach ( $result as $row ) {
			if ( $row instanceof stdClass ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	protected function newGroupFromRow( stdClass $row ): MemberGroup {
		return new MemberGroup(
			id: $this->asInt( $row->mag_id ),
			name: $this->asString( $row->mag_name ),
			creationTimestamp: $this->asTimestamp( $row->mag_timestamp )
		);
	}

	/**
	 * @return string[]
	 */
	protected function groupFields(): array {
		return [ 'mag_id', 'mag_name', 'mag_timestamp' ];
	}

}
