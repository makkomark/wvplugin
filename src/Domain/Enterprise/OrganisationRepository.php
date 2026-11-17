<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Enterprise;

use WitsValley\Members\Support\Repository;

final class OrganisationRepository extends Repository {

	protected string $table = 'organisations';

	protected array $casts = array( 'seats' => 'int', 'level' => 'int' );
}
