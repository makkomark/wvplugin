<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Subscription;

use WitsValley\Members\Domain\Notification\Notifier;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Payment\AutoCharger;
use WitsValley\Members\Domain\Plan\PlanService;
use WitsValley\Members\Support\Clock;

/**
 * Renewal jobs: reminders, renewal due (auto-charge or pay link), grace end, expiry, retries.
 * Every handler re-reads state and is safe to run twice.
 */
final class Dunning {

	public function __construct(
		private SubscriptionRepository $subs,
		private SubscriptionService $service,
		private OrderService $orders,
		private AutoCharger $charger,
		private PlanService $plans
	) {}

	public function reminder( int $subId, int $days ): void {
		$sub = $this->subs->find( $subId );
		if ( ! $sub || 'active' !== $sub['status'] || $sub['cancel_at_period_end'] ) {
			return;
		}
		$order     = $this->orders->ensureRenewalOrder( $sub );
		$key       = 3 >= $days ? 'renewal_3d' : 'renewal_7d';
		$presented = $this->service->get( $subId );
		wvm( Notifier::class )->member(
			(int) $sub['member_id'],
			$key,
			$this->service->payload( $presented ) + array(
				'pay_link' => $order['pay_link'],
				'amount'   => $order['amount_display'],
			),
			'renewal',
			sprintf( 'Renews in %d days', $days ),
			'Pay early — your new period starts from your renewal date.'
		);
	}

	public function renewalDue( int $subId ): void {
		$sub = $this->subs->find( $subId );
		if ( ! $sub || 'active' !== $sub['status'] ) {
			return;
		}
		$end = Clock::parse( $sub['current_period_end'] );
		if ( $end && $end > Clock::now()->modify( '+60 seconds' ) ) {
			// Paid ahead / extended since scheduling: reschedule.
			$this->service->scheduleLifecycle( $this->service->get( $subId ) );
			return;
		}
		if ( $sub['cancel_at_period_end'] ) {
			$this->service->expireCancelled( $sub );
			return;
		}
		$plan = $this->plans->get( (int) $sub['plan_id'] );
		if ( ! Periods::isRecurring( $plan ) ) {
			$this->service->transition( $sub, 'expired', 'Fixed term ended', array( 'expires_at' => Clock::mysql() ) );
			return;
		}
		$order = $this->orders->ensureRenewalOrder( $sub );
		if ( $this->charger->canCharge( $sub ) ) {
			$result = $this->charger->charge( $sub, $order );
			$fresh  = $this->subs->find( $subId );
			if ( $result['ok'] && $fresh && $this->isPaidAhead( $fresh ) ) {
				return;
			}
			$this->subs->update( $subId, array( 'autocharge_attempts' => 0 ) );
			$after = $this->service->markPastDue( $sub, 'Auto-charge failed: ' . $result['message'] );
			wvm( Notifier::class )->member( (int) $sub['member_id'], 'autocharge_failed', $this->service->payload( $after ) + array( 'pay_link' => $order['pay_link'] ) );
			return;
		}
		$after = $this->service->markPastDue( $sub );
		wvm( Notifier::class )->member(
			(int) $sub['member_id'],
			'renewal_due_manual',
			$this->service->payload( $after ) + array(
				'pay_link' => $order['pay_link'],
				'amount'   => $order['amount_display'],
			)
		);
	}

	public function retryAutocharge( int $subId, int $attempt ): void {
		$sub = $this->subs->find( $subId );
		if ( ! $sub || 'past_due' !== $sub['status'] || ! $this->charger->canCharge( $sub ) ) {
			return;
		}
		$order  = $this->orders->ensureRenewalOrder( $sub );
		$result = $this->charger->charge( $sub, $order );
		$fresh  = $this->subs->find( $subId );
		if ( $result['ok'] && $fresh && 'active' === $fresh['status'] ) {
			return;
		}
		$this->subs->update( $subId, array( 'autocharge_attempts' => $attempt ) );
		$this->service->scheduleLifecycle( $this->service->get( $subId ) );
	}

	public function endGrace( int $subId ): void {
		$sub = $this->subs->find( $subId );
		if ( ! $sub || 'past_due' !== $sub['status'] ) {
			return;
		}
		$grace = Clock::parse( $sub['grace_ends_at'] );
		if ( $grace && $grace > Clock::now()->modify( '+60 seconds' ) ) {
			$this->service->scheduleLifecycle( $this->service->get( $subId ) );
			return;
		}
		$this->service->suspend( $sub );
	}

	public function expire( int $subId ): void {
		$sub = $this->subs->find( $subId );
		if ( ! $sub || 'suspended' !== $sub['status'] || ! $sub['expires_at'] ) {
			return;
		}
		$exp = Clock::parse( $sub['expires_at'] );
		if ( $exp && $exp > Clock::now()->modify( '+60 seconds' ) ) {
			$this->service->scheduleLifecycle( $this->service->get( $subId ) );
			return;
		}
		$this->service->expire( $sub );
	}

	public function expireCancelled( int $subId ): void {
		$sub = $this->subs->find( $subId );
		if ( ! $sub || 'active' !== $sub['status'] ) {
			return;
		}
		$end = Clock::parse( $sub['current_period_end'] );
		if ( $end && $end > Clock::now()->modify( '+60 seconds' ) ) {
			$this->service->scheduleLifecycle( $this->service->get( $subId ) );
			return;
		}
		if ( $sub['cancel_at_period_end'] ) {
			$this->service->expireCancelled( $sub );
		} else {
			$this->service->transition( $sub, 'expired', 'Term ended', array( 'expires_at' => Clock::mysql() ) );
		}
	}

	/**
	 * Daily safety net: any subscription whose deadline passed without its job running gets processed.
	 */
	public function reconcile(): int {
		global $wpdb;
		$t     = $this->subs->tableName();
		$now   = Clock::mysql();
		$count = 0;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, status FROM {$t} WHERE organisation_id IS NULL AND ((status = 'active' AND current_period_end IS NOT NULL AND current_period_end < %s) OR (status = 'past_due' AND grace_ends_at < %s) OR (status = 'suspended' AND expires_at IS NOT NULL AND expires_at < %s)) LIMIT 500", $now, $now, $now ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows ?: array() as $r ) {
			$id = (int) $r['id'];
			match ( $r['status'] ) {
				'active'    => $this->renewalDue( $id ),
				'past_due'  => $this->endGrace( $id ),
				'suspended' => $this->expire( $id ),
				default     => null,
			};
			++$count;
		}
		return $count;
	}

	/** @param array<string,mixed> $sub */
	private function isPaidAhead( array $sub ): bool {
		$end = Clock::parse( $sub['current_period_end'] );
		return 'active' === $sub['status'] && $end && $end > Clock::now()->modify( '+60 seconds' );
	}
}
