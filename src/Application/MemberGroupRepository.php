<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\Application;

interface MemberGroupRepository {

	public function createGroup( string $name ): MemberGroup;

	public function renameGroup( int $groupId, string $name ): void;

	/**
	 * Deletes the group without regard for its entries. Use DeleteGroupUseCase instead.
	 */
	public function deleteGroup( int $groupId ): void;

	public function getGroup( int $groupId ): ?MemberGroup;

	/**
	 * The group as the primary database holds it, held against changes from elsewhere until the
	 * request that read it is through. A write that turns on a group having to be there reads it
	 * this way: a replica can still show a group that is gone, and a group read without holding it
	 * can go while what was decided on it is being carried out. Two writes to one group therefore
	 * take their turns, wherever the database can hold a row; SQLite cannot, and serializes its
	 * writers instead.
	 */
	public function lockGroup( int $groupId ): ?MemberGroup;

	/**
	 * Ignores capitalisation, so that two groups cannot be told apart only by it.
	 */
	public function findGroupByName( string $name ): ?MemberGroup;

	/**
	 * @return MemberGroup[]
	 */
	public function listGroups(): array;

}
