<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Workshop;

use WitsValley\Members\Support\Repository;

final class WorkshopRepository extends Repository {

	protected string $table = 'workshops';

	protected array $casts = array(
		'required_level' => 'int',
		'capacity'       => 'int',
	);
}
