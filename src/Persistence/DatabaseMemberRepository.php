<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Persistence;

use ProfessionalWiki\MemberAccess\Application\Member;
use ProfessionalWiki\MemberAccess\Application\MemberCount;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use ProfessionalWiki\MemberAccess\Application\MemberTotals;
use ProfessionalWiki\MemberAccess\Application\NormalizedEmail;
use ProfessionalWiki\MemberAccess\Application\ReadConsistency;
use stdClass;

class DatabaseMemberRepository extends DatabaseRepository implements MemberRepository {

	/**
	 * The group id the members no group admitted are counted under. No group has it, since the
	 * ids the database hands out start at one.
	 */
	private const int NO_GROUP = 0;

	public function recordMember( int $userId, NormalizedEmail $email, ?int $groupId ): void {
		$database = $this->connectionProvider->getPrimaryDatabase();

		$database->newInsertQueryBuilder()
			->insertInto( self::MEMBER_TABLE )
			->ignore()
			->row( [
				'mam_user_id' => $userId,
				'mam_email' => $email->value,
				'mam_group_id' => $groupId,
				'mam_timestamp' => $database->timestamp( $this->now() )
			] )
			->caller( __METHOD__ )
			->execute();
	}

	public function getMember( int $userId, ReadConsistency $consistency ): ?Member {
		return $this->findMemberWhere( [ 'mam_user_id' => $userId ], $consistency );
	}

	public function findMemberByEmail( NormalizedEmail $email ): ?Member {
		return $this->findMemberWhere( [ 'mam_email' => $email->value ], ReadConsistency::MayBeStale );
	}

	/**
	 * @param array<string, mixed> $conditions
	 */
	private function findMemberWhere( array $conditions, ReadConsistency $consistency ): ?Member {
		$row = $this->databaseFor( $consistency )->newSelectQueryBuilder()
			->select( $this->memberFields() )
			->from( self::MEMBER_TABLE )
			->where( $conditions )
			->caller( __METHOD__ )
			->fetchRow();

		return $row === false ? null : $this->newMemberFromRow( $row );
	}

	public function listMembers(): array {
		$rows = $this->toRows(
			$this->connectionProvider->getReplicaDatabase()->newSelectQueryBuilder()
				->select( $this->memberFields() )
				->from( self::MEMBER_TABLE )
				->orderBy( [ 'mam_timestamp', 'mam_user_id' ] )
				->caller( __METHOD__ )
				->fetchResultSet()
		);

		$members = [];

		foreach ( $rows as $row ) {
			$members[] = $this->newMemberFromRow( $row );
		}

		return $members;
	}

	/**
	 * Members no group admitted are counted overall and under no group, so the grouped count they
	 * come back under is added to the overall total and left out of the breakdown.
	 */
	public function getTotals(): MemberTotals {
		$all = $this->countPerGroup( activeOnly: false );
		$active = $this->countPerGroup( activeOnly: true );

		$perGroup = [];

		foreach ( $all as $groupId => $count ) {
			if ( $groupId !== self::NO_GROUP ) {
				$perGroup[$groupId] = new MemberCount( all: $count, active: $active[$groupId] ?? 0 );
			}
		}

		return new MemberTotals(
			overall: new MemberCount( all: array_sum( $all ), active: array_sum( $active ) ),
			perGroup: $perGroup
		);
	}

	/**
	 * The members no group admitted come back grouped under {@see self::NO_GROUP}, which is no
	 * group's id.
	 *
	 * @return array<int, int> Member count per group id
	 */
	private function countPerGroup( bool $activeOnly ): array {
		$query = $this->connectionProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( [ 'mam_group_id', 'member_count' => 'COUNT(*)' ] )
			->from( self::MEMBER_TABLE )
			->groupBy( 'mam_group_id' );

		if ( $activeOnly ) {
			$query->where( [ 'mam_deactivated' => null ] );
		}

		$counts = [];

		foreach ( $this->toRows( $query->caller( __METHOD__ )->fetchResultSet() ) as $row ) {
			$counts[$this->groupIdOf( $row )] = $this->asInt( $row->member_count );
		}

		return $counts;
	}

	private function groupIdOf( stdClass $row ): int {
		return $row->mam_group_id === null ? self::NO_GROUP : $this->asInt( $row->mam_group_id );
	}

	public function deactivateMember( int $userId ): void {
		$database = $this->connectionProvider->getPrimaryDatabase();

		$this->updateMember( $userId, [ 'mam_deactivated' => $database->timestamp( $this->now() ) ] );
	}

	public function reactivateMember( int $userId ): void {
		$this->updateMember( $userId, [ 'mam_deactivated' => null ] );
	}

	public function recordLogin( int $userId ): void {
		$database = $this->connectionProvider->getPrimaryDatabase();

		$this->updateMember( $userId, [ 'mam_last_login' => $database->timestamp( $this->now() ) ] );
	}

	/**
	 * The group is part of the condition rather than checked beforehand, so that two logins
	 * arriving together cannot each read no group and then write a different one.
	 */
	public function attributeToGroup( int $userId, int $groupId ): void {
		$this->connectionProvider->getPrimaryDatabase()->newUpdateQueryBuilder()
			->update( self::MEMBER_TABLE )
			->set( [ 'mam_group_id' => $groupId ] )
			->where( [ 'mam_user_id' => $userId, 'mam_group_id' => null ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param array<string, ?string> $values
	 */
	private function updateMember( int $userId, array $values ): void {
		$this->connectionProvider->getPrimaryDatabase()->newUpdateQueryBuilder()
			->update( self::MEMBER_TABLE )
			->set( $values )
			->where( [ 'mam_user_id' => $userId ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @return string[]
	 */
	private function memberFields(): array {
		return [
			'mam_user_id',
			'mam_email',
			'mam_group_id',
			'mam_timestamp',
			'mam_deactivated',
			'mam_last_login'
		];
	}

	private function newMemberFromRow( stdClass $row ): Member {
		return new Member(
			userId: $this->asInt( $row->mam_user_id ),
			email: $this->asString( $row->mam_email ),
			groupId: $this->asOptionalInt( $row->mam_group_id ),
			creationTimestamp: $this->asTimestamp( $row->mam_timestamp ),
			deactivationTimestamp: $this->asOptionalTimestamp( $row->mam_deactivated ),
			lastLoginTimestamp: $this->asOptionalTimestamp( $row->mam_last_login )
		);
	}

}
