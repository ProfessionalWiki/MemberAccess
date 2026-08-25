<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\MemberAccess\EntryPoints\REST;

use MediaWiki\Rest\Response;
use MediaWiki\Session\CsrfTokenSet;
use ProfessionalWiki\MemberAccess\Application\AllowlistRepository;
use ProfessionalWiki\MemberAccess\Application\MemberGroup;
use ProfessionalWiki\MemberAccess\Application\MemberGroupRepository;
use ProfessionalWiki\MemberAccess\Application\MemberRepository;
use ProfessionalWiki\MemberAccess\Application\MemberTotals;

/**
 * The groups with what they hold: how many entries admit people into them, and how many members
 * they have admitted.
 */
class ListGroupsApi extends MemberAccessApiHandler {

	public function __construct(
		CsrfTokenSet $csrfTokens,
		private readonly MemberGroupRepository $groups,
		private readonly AllowlistRepository $allowlist,
		private readonly MemberRepository $members
	) {
		parent::__construct( $csrfTokens );
	}

	public function run(): Response {
		$totals = $this->members->getTotals();

		return $this->newJsonResponse( [
			'groups' => array_map(
				fn ( MemberGroup $group ): array => $this->groupData( $group, $totals ),
				$this->groups->listGroups()
			)
		] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function groupData( MemberGroup $group, MemberTotals $totals ): array {
		$count = $totals->forGroup( $group->id );

		return [
			'id' => $group->id,
			'name' => $group->name,
			'created' => self::toIso8601( $group->creationTimestamp ),
			'entryCount' => $this->allowlist->countEntries( $group->id ),
			'memberCount' => $count->all,
			'activeMemberCount' => $count->active
		];
	}

	public function needsWriteAccess(): bool {
		return false;
	}

}
