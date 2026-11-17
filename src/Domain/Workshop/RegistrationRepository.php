<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Workshop;

use WitsValley\Members\Support\Repository;

final class RegistrationRepository extends Repository {

	protected string $table = 'workshop_registrations';

	protected bool $hasUpdatedAt = false;
}
