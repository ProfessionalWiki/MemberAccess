<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use ProfessionalWiki\MemberAccess\Application\AllowlistEntry;
use ProfessionalWiki\MemberAccess\Application\AllowlistRepository;
use ProfessionalWiki\MemberAccess\Application\AllowlistValue;
use ProfessionalWiki\MemberAccess\Application\EntryKind;
use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use stdClass;

class DatabaseAllowlistRepository extends DatabaseRepository implements AllowlistRepository {

	public function addEntry( int $groupId, AllowlistValue $value, int $actorId ): ?AllowlistEntry {
		$database = $this->connectionProvider->getPrimaryDatabase();
		$timestamp = $this->now();

		$database->newInsertQueryBuilder()
			->insertInto( self::ENTRY_TABLE )
			->ignore()
			->row( [
				'mae_group_id' => $groupId,
				'mae_value' => $value->value,
				'mae_kind' => $value->kind->value,
				'mae_actor' => $actorId,
				'mae_timestamp' => $database->timestamp( $timestamp )
			] )
			->caller( __METHOD__ )
			->execute();

		if ( $database->affectedRows() === 0 ) {
			return null;
		}

		return new AllowlistEntry(
			id: $database->insertId(),
			groupId: $groupId,
			value: $value,
			actorId: $actorId,
			creationTimestamp: $timestamp
		);
	}

	public function getEntry( int $entryId ): ?AllowlistEntry {
		$row = $this->connectionProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( $this->entryFields() )
			->from( self::ENTRY_TABLE )
			->where( [ 'mae_id' => $entryId ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row === false ? null : $this->newEntryFromRow( $row );
	}

	public function removeEntry( int $entryId ): void {
		$this->connectionProvider->getPrimaryDatabase()->newDeleteQueryBuilder()
			->deleteFrom( self::ENTRY_TABLE )
			->where( [ 'mae_id' => $entryId ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function listEntries( int $groupId ): array {
		$rows = $this->toRows(
			$this->connectionProvider->getReplicaDatabase()->newSelectQueryBuilder()
				->select( $this->entryFields() )
				->from( self::ENTRY_TABLE )
				->where( [ 'mae_group_id' => $groupId ] )
				->orderBy( 'mae_value' )
				->caller( __METHOD__ )
				->fetchResultSet()
		);

		$entries = [];

		foreach ( $rows as $row ) {
			$entries[] = $this->newEntryFromRow( $row );
		}

		return $entries;
	}

	public function countEntries( int $groupId ): int {
		return $this->connectionProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->from( self::ENTRY_TABLE )
			->where( [ 'mae_group_id' => $groupId ] )
			->caller( __METHOD__ )
			->fetchRowCount();
	}

	/**
	 * One row at most, and held rather than counted: a locking read sees what was committed a
	 * moment ago, where a plain one answers from the snapshot the transaction started with.
	 */
	public function groupHasEntries( int $groupId ): bool {
		return $this->connectionProvider->getPrimaryDatabase()->newSelectQueryBuilder()
			->select( 'mae_id' )
			->from( self::ENTRY_TABLE )
			->where( [ 'mae_group_id' => $groupId ] )
			->forUpdate()
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchRow() !== false;
	}

	public function findGroupForValue( AllowlistValue $value, ReadConsistency $consistency ): ?MemberGroup {
		$row = $this->databaseFor( $consistency )->newSelectQueryBuilder()
			->select( $this->groupFields() )
			->from( self::ENTRY_TABLE )
			->join( self::GROUP_TABLE, null, 'mag_id = mae_group_id' )
			->where( [ 'mae_value' => $value->value ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row === false ? null : $this->newGroupFromRow( $row );
	}

	/**
	 * @return string[]
	 */
	private function entryFields(): array {
		return [ 'mae_id', 'mae_group_id', 'mae_value', 'mae_kind', 'mae_actor', 'mae_timestamp' ];
	}

	private function newEntryFromRow( stdClass $row ): AllowlistEntry {
		return new AllowlistEntry(
			id: $this->asInt( $row->mae_id ),
			groupId: $this->asInt( $row->mae_group_id ),
			value: AllowlistValue::fromStorage(
				value: $this->asString( $row->mae_value ),
				kind: EntryKind::from( $this->asString( $row->mae_kind ) )
			),
			actorId: $this->asInt( $row->mae_actor ),
			creationTimestamp: $this->asTimestamp( $row->mae_timestamp )
		);
	}

}
