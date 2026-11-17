<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Order;

use WitsValley\Members\Support\Repository;

final class HookInboxRepository extends Repository {

	protected string $table = 'hook_inbox';

	protected bool $hasUpdatedAt = false;

	protected array $casts = array( 'payload' => 'json' );
}
