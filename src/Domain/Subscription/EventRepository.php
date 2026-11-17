<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Subscription;

use WitsValley\Members\Support\Repository;

final class EventRepository extends Repository {

	protected string $table = 'subscription_events';

	protected bool $hasUpdatedAt = false;
}
