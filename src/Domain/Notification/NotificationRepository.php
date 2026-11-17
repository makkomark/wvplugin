<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Notification;

use WitsValley\Members\Support\Repository;

final class NotificationRepository extends Repository {

	protected string $table = 'notifications';

	protected bool $hasUpdatedAt = false;

	protected array $casts = array(
		'payload'  => 'json',
		'attempts' => 'int',
	);
}
