<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\REST;

use MediaWiki\Rest\Response;
use MediaWiki\Session\CsrfTokenSet;
use ProfessionalWiki\MemberAccess\Application\Member;
use ProfessionalWiki\MemberAccess\Application\MemberGroupRepository;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use ProfessionalWiki\MemberAccess\Application\MemberTotals;
use ProfessionalWiki\MemberAccess\Application\Schema;

/**
 * The roster, and the counts that are the billing meter.
 */
class ListMembersApi extends MemberAccessApiHandler {

	public function __construct(
		CsrfTokenSet $csrfTokens,
		Schema $schema,
		private readonly MemberRepository $members,
		private readonly MemberGroupRepository $groups
	) {
		parent::__construct( $csrfTokens, $schema );
	}

	public function run(): Response {
		$groupNames = $this->groupNames();
		$totals = $this->members->getTotals();

		return $this->newJsonResponse( [
			'members' => array_map(
				fn ( Member $member ): array => $this->memberData( $member, $groupNames ),
				$this->members->listMembers()
			),
			'totals' => [
				'all' => $totals->overall->all,
				'active' => $totals->overall->active,
				'perGroup' => $this->perGroupTotals( $totals, $groupNames )
			]
		] );
	}

	/**
	 * A member no group admitted carries neither a group id nor a name.
	 *
	 * @param array<int, string> $groupNames
	 * @return array<string, mixed>
	 */
	private function memberData( Member $member, array $groupNames ): array {
		return [
			'userId' => $member->userId,
			'email' => $member->email,
			'groupId' => $member->groupId,
			'groupName' => $member->groupId === null ? null : ( $groupNames[$member->groupId] ?? null ),
			'created' => self::toIso8601( $member->creationTimestamp ),
			'lastLogin' => self::toIso8601( $member->lastLoginTimestamp ),
			'active' => $member->isActive()
		];
	}

	/**
	 * Every group is counted, including the ones that have admitted nobody yet, so the panel can
	 * show a roster broken down by group without filling in the gaps itself.
	 *
	 * @param array<int, string> $groupNames
	 * @return array<int, array<string, mixed>>
	 */
	private function perGroupTotals( MemberTotals $totals, array $groupNames ): array {
		$perGroup = [];

		foreach ( $groupNames as $groupId => $name ) {
			$count = $totals->forGroup( $groupId );

			$perGroup[] = [
				'groupId' => $groupId,
				'groupName' => $name,
				'all' => $count->all,
				'active' => $count->active
			];
		}

		return $perGroup;
	}

	/**
	 * @return array<int, string>
	 */
	private function groupNames(): array {
		$names = [];

		foreach ( $this->groups->listGroups() as $group ) {
			$names[$group->id] = $group->name;
		}

		return $names;
	}

	public function needsWriteAccess(): bool {
		return false;
	}

}
