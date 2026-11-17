<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Workshop;

use WitsValley\Members\Support\Repository;

final class ConsultationRepository extends Repository {

	protected string $table = 'consultation_requests';
}
