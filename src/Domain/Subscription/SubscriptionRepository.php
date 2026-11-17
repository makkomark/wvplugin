<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Subscription;

use WitsValley\Members\Support\Repository;

final class SubscriptionRepository extends Repository {

	protected string $table = 'subscriptions';

	protected array $casts = array(
		'amount_minor'         => 'int',
		'cancel_at_period_end' => 'bool',
		'autocharge_attempts'  => 'int',
	);

	/**
	 * The member's current personal subscription: live (active/past_due/suspended) first, else the latest.
	 *
	 * @return array<string,mixed>|null
	 */
	public function current( int $memberId ): ?array {
		global $wpdb;
		$t   = $this->tableName();
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE member_id = %d AND organisation_id IS NULL AND status <> 'pending'
				ORDER BY (status IN ('active','past_due','suspended')) DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB
				$memberId
			),
			ARRAY_A
		);
		return $row ? $this->hydrate( $row ) : null;
	}

	/** @return list<array<string,mixed>> Active/past_due subscriptions for a member (personal + org). */
	public function live( int $memberId ): array {
		return $this->where(
			array(
				'member_id' => $memberId,
				'status'    => array( 'active', 'past_due' ),
			)
		);
	}
}
