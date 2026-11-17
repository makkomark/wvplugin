<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Enterprise;

use WitsValley\Members\Support\Repository;

final class ApplicationRepository extends Repository {

	protected string $table = 'applications';

	protected array $casts = array( 'participants' => 'int' );
}
