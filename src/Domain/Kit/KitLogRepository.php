<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Kit;

use WitsValley\Members\Support\Repository;

final class KitLogRepository extends Repository {

	protected string $table = 'kit_sync_log';

	protected bool $hasUpdatedAt = false;
}
