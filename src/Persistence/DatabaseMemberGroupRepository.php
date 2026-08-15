<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use ProfessionalWiki\MemberAccess\Application\MemberGroupRepository;
use Wikimedia\Rdbms\SelectQueryBuilder;

class DatabaseMemberGroupRepository extends DatabaseRepository implements MemberGroupRepository {

	public function createGroup( string $name ): MemberGroup {
		$database = $this->connectionProvider->getPrimaryDatabase();
		$timestamp = $this->now();

		$database->newInsertQueryBuilder()
			->insertInto( self::GROUP_TABLE )
			->row( [
				'mag_name' => $name,
				'mag_timestamp' => $database->timestamp( $timestamp )
			] )
			->caller( __METHOD__ )
			->execute();

		return new MemberGroup(
			id: $database->insertId(),
			name: $name,
			creationTimestamp: $timestamp
		);
	}

	public function renameGroup( int $groupId, string $name ): void {
		$this->connectionProvider->getPrimaryDatabase()->newUpdateQueryBuilder()
			->update( self::GROUP_TABLE )
			->set( [ 'mag_name' => $name ] )
			->where( [ 'mag_id' => $groupId ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function deleteGroup( int $groupId ): void {
		$this->connectionProvider->getPrimaryDatabase()->newDeleteQueryBuilder()
			->deleteFrom( self::GROUP_TABLE )
			->where( [ 'mag_id' => $groupId ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function getGroup( int $groupId ): ?MemberGroup {
		$row = $this->newSelectQuery()
			->where( [ 'mag_id' => $groupId ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row === false ? null : $this->newGroupFromRow( $row );
	}

	/**
	 * Compared in PHP rather than in SQL, because case insensitive comparison of a binary column
	 * differs per database type. A wiki has one group per party it admits members for, so the list
	 * this walks is short.
	 */
	public function findGroupByName( string $name ): ?MemberGroup {
		$wanted = mb_strtolower( $name );

		foreach ( $this->listGroups() as $group ) {
			if ( mb_strtolower( $group->name ) === $wanted ) {
				return $group;
			}
		}

		return null;
	}

	public function listGroups(): array {
		$rows = $this->toRows(
			$this->newSelectQuery()
				->orderBy( 'mag_id' )
				->caller( __METHOD__ )
				->fetchResultSet()
		);

		$groups = [];

		foreach ( $rows as $row ) {
			$groups[] = $this->newGroupFromRow( $row );
		}

		return $groups;
	}

	private function newSelectQuery(): SelectQueryBuilder {
		return $this->connectionProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( $this->groupFields() )
			->from( self::GROUP_TABLE );
	}

}
