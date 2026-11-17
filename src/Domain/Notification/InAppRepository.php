<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Notification;

use WitsValley\Members\Support\Repository;

final class InAppRepository extends Repository {

	protected string $table = 'in_app_notifications';

	protected bool $hasUpdatedAt = false;
}
