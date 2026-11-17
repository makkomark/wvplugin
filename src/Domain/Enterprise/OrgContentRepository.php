<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Enterprise;

use WitsValley\Members\Support\Repository;

final class OrgContentRepository extends Repository {

	protected string $table = 'organisation_content';

	protected bool $timestamps = false;
}
