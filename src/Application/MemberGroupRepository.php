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
	 * Ignores capitalisation, so that two groups cannot be told apart only by it.
	 */
	public function findGroupByName( string $name ): ?MemberGroup;

	/**
	 * @return MemberGroup[]
	 */
	public function listGroups(): array;

}
