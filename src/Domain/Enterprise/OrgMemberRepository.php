<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Enterprise;

use WitsValley\Members\Support\Repository;

final class OrgMemberRepository extends Repository {

	protected string $table = 'organisation_members';

	protected bool $timestamps = false;
}
