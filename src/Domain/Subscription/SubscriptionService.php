<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Subscription;

use DateTimeImmutable;
use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Member\MemberRepository;
use WitsValley\Members\Domain\Notification\Notifier;
use WitsValley\Members\Domain\Order\OrderRepository;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Plan\PlanService;
use WitsValley\Members\Jobs\Scheduler;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Money;
use WitsValley\Members\Support\Pagination;
use WitsValley\Members\Support\Settings;

/**
 * Subscription lifecycle. Every transition: subscription_events + audit + Kit sync (via action) + notification.
 */
final class SubscriptionService {

	public function __construct(
		private SubscriptionRepository $subs,
		private EventRepository $events,
		private MemberRepository $members,
		private PlanService $plans,
		private OrderRepository $orders,
		private Audit $audit,
		private Settings $settings,
		private Scheduler $scheduler
	) {}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------- */

	/** @return array<string,mixed> */
	public function get( int $id ): array {
		return $this->present( $this->subs->findOrFail( $id, 'subscription' ) );
	}

	/** @return array<string,mixed> */
	public function raw( int $id ): array {
		return $this->subs->findOrFail( $id, 'subscription' );
	}

	/** @return array<string,mixed>|null */
	public function currentFor( int $memberId ): ?array {
		$row = $this->subs->current( $memberId );
		return $row ? $this->present( $row ) : null;
	}

	/** @return list<array<string,mixed>> */
	public function forMember( int $memberId ): array {
		return array_map( array( $this, 'present' ), $this->subs->where( array( 'member_id' => $memberId ), 'id DESC' ) );
	}

	/** @return list<array<string,mixed>> */
	public function eventsFor( int $subId ): array {
		return array_map(
			static function ( array $e ): array {
				$actor = $e['actor_id'] ? get_userdata( (int) $e['actor_id'] ) : null;
				return array(
					'id'          => (int) $e['id'],
					'from_status' => $e['from_status'],
					'to_status'   => $e['to_status'],
					'reason'      => $e['reason'],
					'actor_type'  => $e['actor_type'],
					'actor_name'  => $actor ? $actor->display_name : ucfirst( (string) $e['actor_type'] ),
					'created_at'  => Clock::iso( $e['created_at'] ),
				);
			},
			$this->events->where( array( 'subscription_id' => $subId ), 'id DESC' )
		);
	}

	/**
	 * @param array<string,mixed> $f status, plan, due_before, member
	 * @return array{items:list<array<string,mixed>>, meta:array<string,int>}
	 */
	public function list( array $f, Pagination $p ): array {
		$cond = array();
		$args = array();
		if ( ! empty( $f['status'] ) ) {
			$cond[] = 'status = %s';
			$args[] = (string) $f['status'];
		}
		if ( ! empty( $f['plan'] ) ) {
			$cond[] = 'plan_id = %d';
			$args[] = (int) $f['plan'];
		}
		if ( ! empty( $f['member'] ) ) {
			$cond[] = 'member_id = %d';
			$args[] = (int) $f['member'];
		}
		if ( ! empty( $f['due_before'] ) ) {
			$cond[] = 'current_period_end <= %s';
			$args[] = gmdate( 'Y-m-d 23:59:59', (int) strtotime( (string) $f['due_before'] ) );
		}
		$res          = $this->subs->paginate( $cond, $args, $p, 'current_period_end ASC, id DESC' );
		$res['items'] = array_map( array( $this, 'present' ), $res['items'] );
		return $res;
	}

	/* ---------------------------------------------------------------------
	 * Creation
	 * ------------------------------------------------------------------- */

	/** Pending subscription created alongside a "new" order. */
	public function createPending( int $memberId, int $planId, string $currency, int $amountMinor, ?int $orgId = null ): int {
		$id = $this->subs->insert(
			array(
				'member_id'       => $memberId,
				'plan_id'         => $planId,
				'organisation_id' => $orgId,
				'status'          => 'pending',
				'currency'        => strtoupper( $currency ),
				'amount_minor'    => $amountMinor,
			)
		);
		$this->recordEvent( $id, null, 'pending', 'Checkout started' );
		return $id;
	}

	/**
	 * Activate a subscription (pending → active). Creates the row if $subId is null.
	 *
	 * @param array<string,mixed>|null $order Plugin order row that paid for it.
	 * @return array<string,mixed>
	 */
	public function start( int $memberId, int $planId, ?array $order = null, ?int $subId = null, string $reason = 'Payment received', ?int $orgId = null, ?DateTimeImmutable $endOverride = null ): array {
		$plan  = $this->plans->get( $planId );
		$now   = Clock::now();
		$subId = $subId ?: ( $order['subscription_id'] ?? null );
		if ( ! $subId ) {
			$subId = $this->createPending( $memberId, $planId, (string) ( $order['currency'] ?? $this->defaultCurrency() ), (int) ( $order['amount_minor'] ?? 0 ), $orgId );
		}
		$sub = $this->subs->findOrFail( (int) $subId );
		if ( 'active' === $sub['status'] ) {
			return $this->present( $sub );
		}
		$trial = $this->isFirstPaid( $memberId ) ? (int) $plan['trial_days'] : 0;
		$end   = $endOverride ?? ( $plan['is_free'] ? null : Periods::next( $now, $plan, $trial ) );
		$this->supersedeOthers( $memberId, (int) $subId, $orgId );
		$this->transition(
			$sub,
			'active',
			$reason,
			array(
				'plan_id'              => $planId,
				'started_at'           => Clock::mysql( $now ),
				'current_period_start' => $plan['is_free'] ? null : Clock::mysql( $now ),
				'current_period_end'   => $end ? Clock::mysql( $end ) : null,
				'currency'             => $order['currency'] ?? $sub['currency'],
				'amount_minor'         => $order ? (int) $order['amount_minor'] : (int) $sub['amount_minor'],
			)
		);
		$fresh = $this->get( (int) $subId );
		if ( ! $orgId ) {
			$this->notifier()->member( $memberId, 'welcome', $this->payload( $fresh ), 'welcome', 'Welcome to ' . $plan['name'], 'Your membership is active.' );
		}
		return $fresh;
	}

	/**
	 * Free membership for a member (no order).
	 *
	 * @return array<string,mixed>
	 */
	public function startFree( int $memberId ): array {
		$free = $this->plans->findBySlug( 'free' ) ?? null;
		if ( ! $free ) {
			foreach ( $this->plans->list() as $p ) {
				if ( $p['is_free'] ) {
					$free = $p;
					break;
				}
			}
		}
		if ( ! $free ) {
			throw DomainError::notFound( 'free plan' );
		}
		$current = $this->subs->current( $memberId );
		if ( $current && in_array( $current['status'], array( 'active', 'past_due', 'suspended' ), true ) ) {
			return $this->present( $current );
		}
		return $this->start( $memberId, (int) $free['id'], null, null, 'Joined free plan' );
	}

	/**
	 * Admin grant without payment (scholarship, comp).
	 *
	 * @return array<string,mixed>
	 */
	public function adminGrant( int $memberId, int $planId, string $reason, bool $startNow = true, ?int $days = null ): array {
		$this->requireReason( $reason );
		$this->members->findOrFail( $memberId, 'member' );
		$plan = $this->plans->get( $planId );
		$end  = $days ? Clock::now()->modify( '+' . $days . ' days' ) : null;
		$id   = $this->createPending( $memberId, $planId, $this->defaultCurrency(), 0 );
		if ( ! $startNow ) {
			$this->audit->log( 'subscription.granted_pending', 'subscription', $id, null, $this->get( $id ), $reason );
			return $this->get( $id );
		}
		$order_id = $this->orders->insert(
			array(
				'member_id'       => $memberId,
				'plan_id'         => $planId,
				'subscription_id' => $id,
				'type'            => 'manual_adjust',
				'currency'        => $this->defaultCurrency(),
				'amount_minor'    => 0,
				'status'          => 'paid',
				'paid_at'         => Clock::mysql(),
				'description'     => 'Admin grant: ' . $reason,
			)
		);
		$sub      = $this->start( $memberId, $planId, $this->orders->find( $order_id ), $id, 'Admin grant: ' . $reason, null, $end ?? ( $plan['is_free'] ? null : Periods::next( Clock::now(), $plan ) ) );
		$this->audit->log( 'subscription.granted', 'subscription', $id, null, $sub, $reason );
		return $sub;
	}

	/* ---------------------------------------------------------------------
	 * Renewals & dunning
	 * ------------------------------------------------------------------- */

	/**
	 * Paid renewal: extend from the previous period end (never from payment time).
	 *
	 * @param array<string,mixed> $order
	 * @return array<string,mixed>
	 */
	public function renew( array $sub, array $order ): array {
		$sub = $this->subs->findOrFail( (int) $sub['id'] );
		if ( in_array( $sub['status'], array( 'suspended', 'expired' ), true ) ) {
			return $this->restore( $sub, $order );
		}
		$plan_id         = (int) ( $sub['pending_plan_id'] ?: $order['plan_id'] ?: $sub['plan_id'] );
		$plan            = $this->plans->get( $plan_id );
		[ $start, $end ] = $this->anchoredPeriod( $sub, $plan, $order );
		$fields          = array(
			'plan_id'              => $plan_id,
			'pending_plan_id'      => null,
			'current_period_start' => Clock::mysql( $start ),
			'current_period_end'   => $end ? Clock::mysql( $end ) : null,
			'grace_ends_at'        => null,
			'amount_minor'         => (int) $order['amount_minor'],
			'currency'             => $order['currency'],
			'autocharge_attempts'  => 0,
		);
		$was             = (string) $sub['status'];
		if ( 'active' === $was ) {
			$before = $this->present( $sub );
			$this->subs->update( (int) $sub['id'], $fields );
			$this->recordEvent( (int) $sub['id'], 'active', 'active', 'Renewed' );
			$after = $this->get( (int) $sub['id'] );
			$this->audit->log( 'subscription.renewed', 'subscription', (int) $sub['id'], $before, $after );
			$this->afterChange( $after, 'active', 'active' );
		} else {
			$this->transition( $sub, 'active', 'Renewal paid', $fields );
			$after = $this->get( (int) $sub['id'] );
			$this->notifier()->member( (int) $sub['member_id'], 'restored', $this->payload( $after ), 'restored', 'Membership restored', 'Thanks — your access is back.' );
		}
		if ( $plan_id !== (int) $sub['plan_id'] ) {
			$this->notifier()->member( (int) $sub['member_id'], 'plan_changed', $this->payload( $after ), 'plan_changed', 'Plan changed', 'You are now on ' . $plan['name'] . '.' );
		}
		return $after;
	}

	/**
	 * Restore a past_due/suspended/expired subscription after a paid order.
	 *
	 * @param array<string,mixed> $sub
	 * @param array<string,mixed> $order
	 * @return array<string,mixed>
	 */
	public function restore( array $sub, array $order ): array {
		$sub = $this->subs->findOrFail( (int) $sub['id'] );
		if ( 'active' === $sub['status'] ) {
			return $this->renew( $sub, $order );
		}
		if ( 'expired' === $sub['status'] && ! $this->withinRejoinWindow( $sub ) ) {
			throw new DomainError( 'This membership expired outside the rejoin window.', 'wvm_rejoin_window', 409 );
		}
		$plan_id         = (int) ( $sub['pending_plan_id'] ?: $order['plan_id'] ?: $sub['plan_id'] );
		$plan            = $this->plans->get( $plan_id );
		[ $start, $end ] = $this->anchoredPeriod( $sub, $plan, $order );
		$this->transition(
			$sub,
			'active',
			'Restored after payment',
			array(
				'plan_id'              => $plan_id,
				'pending_plan_id'      => null,
				'current_period_start' => Clock::mysql( $start ),
				'current_period_end'   => $end ? Clock::mysql( $end ) : null,
				'grace_ends_at'        => null,
				'suspended_at'         => null,
				'expires_at'           => null,
				'amount_minor'         => (int) $order['amount_minor'],
				'currency'             => $order['currency'],
				'autocharge_attempts'  => 0,
			)
		);
		$after = $this->get( (int) $sub['id'] );
		$this->notifier()->member( (int) $sub['member_id'], 'restored', $this->payload( $after ), 'restored', 'Membership restored', 'Thanks — your access is back.' );
		return $after;
	}

	/** @param array<string,mixed> $sub */
	public function markPastDue( array $sub, string $reason = 'Renewal unpaid at period end' ): array {
		$sub   = $this->subs->findOrFail( (int) $sub['id'] );
		$end   = Clock::parse( $sub['current_period_end'] ) ?? Clock::now();
		$grace = $end->modify( '+' . $this->settings->lifecycleSeconds( (int) $this->settings->get( 'lifecycle', 'grace_days', 7 ) ) . ' seconds' );
		$this->transition( $sub, 'past_due', $reason, array( 'grace_ends_at' => Clock::mysql( $grace ) ) );
		$after = $this->get( (int) $sub['id'] );
		$this->notifier()->member( (int) $sub['member_id'], 'grace_started', $this->payload( $after ), 'grace_started', 'Payment overdue', 'Pay by ' . $after['grace_ends_display'] . ' to keep access.' );
		return $after;
	}

	/** @param array<string,mixed> $sub */
	public function suspend( array $sub, string $reason = 'Grace period ended', bool $scheduleExpiry = true ): array {
		$sub     = $this->subs->findOrFail( (int) $sub['id'] );
		$now     = Clock::now();
		$expires = $scheduleExpiry ? $now->modify( '+' . $this->settings->lifecycleSeconds( (int) $this->settings->get( 'lifecycle', 'suspend_to_expire_days', 30 ) ) . ' seconds' ) : null;
		$this->transition(
			$sub,
			'suspended',
			$reason,
			array(
				'suspended_at' => Clock::mysql( $now ),
				'expires_at'   => $expires ? Clock::mysql( $expires ) : null,
			)
		);
		$after = $this->get( (int) $sub['id'] );
		$this->notifier()->member( (int) $sub['member_id'], 'suspended', $this->payload( $after ), 'suspended', 'Membership suspended', 'Reactivate any time to restore access.' );
		return $after;
	}

	/** @param array<string,mixed> $sub */
	public function expire( array $sub, string $reason = 'Suspension period ended' ): array {
		$sub = $this->subs->findOrFail( (int) $sub['id'] );
		$this->transition( $sub, 'expired', $reason, array( 'expires_at' => Clock::mysql() ) );
		$after = $this->get( (int) $sub['id'] );
		if ( ! $sub['organisation_id'] ) {
			$this->notifier()->member( (int) $sub['member_id'], 'expired', $this->payload( $after ), 'expired', 'Membership expired', 'You can rejoin within ' . (int) $this->settings->get( 'lifecycle', 'rejoin_window_days', 365 ) . ' days.' );
		}
		return $after;
	}

	/* ---------------------------------------------------------------------
	 * Plan changes
	 * ------------------------------------------------------------------- */

	/**
	 * Prorated upgrade preview: (newPrice - oldPrice) * daysRemaining / daysInPeriod.
	 *
	 * @param array<string,mixed> $sub
	 * @return array<string,mixed>
	 */
	public function upgradePreview( array $sub, int $newPlanId, ?string $currency = null ): array {
		$sub      = $this->subs->findOrFail( (int) $sub['id'] );
		$new      = $this->plans->get( $newPlanId );
		$currency = strtoupper( $currency ?: (string) $sub['currency'] );
		$price    = $new['prices'][ $currency ] ?? null;
		if ( null === $price ) {
			throw DomainError::validation( array( 'plan_id' => 'This plan is not available in ' . $currency . '.' ) );
		}
		$old_plan  = $this->plans->get( (int) $sub['plan_id'] );
		$old_price = $old_plan['is_free'] ? 0 : (int) ( $old_plan['prices'][ $currency ] ?? $sub['amount_minor'] );
		$start     = Clock::parse( $sub['current_period_start'] );
		$end       = Clock::parse( $sub['current_period_end'] );
		$now       = Clock::now();
		if ( $old_plan['is_free'] || ! $start || ! $end || $end <= $now || ! StateMachine::grantsAccess( (string) $sub['status'] ) ) {
			return array(
				'mode'           => 'new',
				'plan'           => $new,
				'currency'       => $currency,
				'amount_minor'   => (int) $price,
				'amount_display' => Money::format( (int) $price, $currency ),
				'days_remaining' => 0,
				'days_in_period' => 0,
				'is_upgrade'     => true,
				'note'           => 'Full price — a new billing period starts today.',
			);
		}
		$days_in   = max( 1, (int) round( ( $end->getTimestamp() - $start->getTimestamp() ) / DAY_IN_SECONDS ) );
		$days_left = max( 0, (int) ceil( ( $end->getTimestamp() - $now->getTimestamp() ) / DAY_IN_SECONDS ) );
		$amount    = (int) round( ( (int) $price - $old_price ) * $days_left / $days_in );
		$is_up     = (int) $new['level'] > (int) $old_plan['level'] || (int) $price > $old_price;
		return array(
			'mode'           => 'prorated',
			'plan'           => $new,
			'currency'       => $currency,
			'amount_minor'   => max( 0, $amount ),
			'amount_display' => Money::format( max( 0, $amount ), $currency ),
			'new_price'      => (int) $price,
			'old_price'      => $old_price,
			'days_remaining' => $days_left,
			'days_in_period' => $days_in,
			'is_upgrade'     => $is_up,
			'note'           => sprintf( 'Prorated for %d of %d days left. From your next renewal you pay %s.', $days_left, $days_in, Money::format( (int) $price, $currency ) ),
		);
	}

	/**
	 * Change plan. immediate: returns pending upgrade order (or applies directly when free/admin);
	 * at_renewal: flags pending_plan_id and switches at the next renewal.
	 *
	 * @param array<string,mixed> $sub
	 * @return array<string,mixed> {subscription, order?, checkout_url?}
	 */
	public function changePlan( array $sub, int $newPlanId, string $mode = 'immediate', bool $chargeDifference = true, ?string $reason = null ): array {
		$sub = $this->subs->findOrFail( (int) $sub['id'] );
		if ( (int) $sub['plan_id'] === $newPlanId ) {
			throw DomainError::conflict( 'Already on this plan.' );
		}
		$new = $this->plans->get( $newPlanId );
		if ( $new['archived_at'] ) {
			throw DomainError::conflict( 'That plan is no longer available.' );
		}
		if ( 'at_renewal' === $mode ) {
			$before = $this->present( $sub );
			$this->subs->update( (int) $sub['id'], array( 'pending_plan_id' => $newPlanId ) );
			$after = $this->get( (int) $sub['id'] );
			$this->audit->log( 'subscription.plan_change_scheduled', 'subscription', (int) $sub['id'], $before, $after, $reason );
			$this->notifier()->member( (int) $sub['member_id'], 'plan_changed', $this->payload( $after ), 'plan_changed', 'Plan change scheduled', 'You will move to ' . $new['name'] . ' at your next renewal.' );
			$this->scheduleLifecycle( $after );
			return array( 'subscription' => $after );
		}
		if ( $chargeDifference ) {
			$preview = $this->upgradePreview( $sub, $newPlanId );
			if ( $preview['amount_minor'] > 0 ) {
				$type  = 'new' === $preview['mode'] ? 'new' : 'upgrade';
				$order = $this->orderService()->createForPlan(
					(int) $sub['member_id'],
					$newPlanId,
					$type,
					(string) $preview['currency'],
					null,
					null,
					'new' === $type ? null : (int) $sub['id'],
					(int) $preview['amount_minor']
				);
				return array(
					'subscription' => $this->present( $sub ),
					'order'        => $order,
					'checkout_url' => $order['checkout_url'],
					'preview'      => $preview,
				);
			}
		}
		return array( 'subscription' => $this->applyPlanChange( $sub, $newPlanId, null, $reason ?? 'Plan changed' ) );
	}

	/**
	 * Apply an immediate plan change (after upgrade payment or admin override).
	 *
	 * @param array<string,mixed>      $sub
	 * @param array<string,mixed>|null $order
	 * @return array<string,mixed>
	 */
	public function applyPlanChange( array $sub, int $newPlanId, ?array $order = null, string $reason = 'Upgrade paid' ): array {
		$sub    = $this->subs->findOrFail( (int) $sub['id'] );
		$new    = $this->plans->get( $newPlanId );
		$before = $this->present( $sub );
		$price  = $new['prices'][ (string) $sub['currency'] ] ?? $sub['amount_minor'];
		$this->subs->update(
			(int) $sub['id'],
			array(
				'plan_id'         => $newPlanId,
				'pending_plan_id' => null,
				'amount_minor'    => (int) $price,
			)
		);
		$this->recordEvent( (int) $sub['id'], (string) $sub['status'], (string) $sub['status'], $reason . ': ' . $before['plan']['name'] . ' → ' . $new['name'] );
		$after = $this->get( (int) $sub['id'] );
		$this->audit->log( 'subscription.plan_changed', 'subscription', (int) $sub['id'], $before, $after, $reason );
		$this->afterChange( $after, (string) $sub['status'], (string) $sub['status'] );
		$this->notifier()->member( (int) $sub['member_id'], 'plan_changed', $this->payload( $after ), 'plan_changed', 'Plan changed', 'You are now on ' . $new['name'] . '.' );
		return $after;
	}

	/* ---------------------------------------------------------------------
	 * Cancel / resume
	 * ------------------------------------------------------------------- */

	/** @param array<string,mixed> $sub */
	public function cancel( array $sub, string $reason, bool $immediate = false ): array {
		$sub = $this->subs->findOrFail( (int) $sub['id'] );
		if ( ! in_array( $sub['status'], array( 'active', 'past_due', 'suspended', 'pending' ), true ) ) {
			throw DomainError::conflict( 'This subscription cannot be cancelled.' );
		}
		$reason = sanitize_text_field( $reason ) ?: 'No reason given';
		$plan   = $this->plans->get( (int) $sub['plan_id'] );
		if ( $immediate || 'active' !== $sub['status'] || $plan['is_free'] || ! $sub['current_period_end'] ) {
			$this->transition(
				$sub,
				'cancelled',
				'Cancelled: ' . $reason,
				array(
					'cancelled_at'  => Clock::mysql(),
					'cancel_reason' => mb_substr( $reason, 0, 100 ),
				)
			);
			return $this->get( (int) $sub['id'] );
		}
		$before = $this->present( $sub );
		$this->subs->update(
			(int) $sub['id'],
			array(
				'cancel_at_period_end' => true,
				'cancel_reason'        => mb_substr( $reason, 0, 100 ),
			)
		);
		$this->recordEvent( (int) $sub['id'], 'active', 'active', 'Cancellation scheduled: ' . $reason );
		$after = $this->get( (int) $sub['id'] );
		$this->audit->log( 'subscription.cancel_scheduled', 'subscription', (int) $sub['id'], $before, $after, $reason );
		$this->afterChange( $after, 'active', 'active' );
		$this->notifier()->member( (int) $sub['member_id'], 'cancel_scheduled', $this->payload( $after ), 'cancel_scheduled', 'Cancellation scheduled', 'Access continues until ' . $after['period_end_display'] . '.' );
		return $after;
	}

	/** @param array<string,mixed> $sub */
	public function resume( array $sub ): array {
		$sub = $this->subs->findOrFail( (int) $sub['id'] );
		if ( 'active' !== $sub['status'] || ! $sub['cancel_at_period_end'] ) {
			throw DomainError::conflict( 'There is no scheduled cancellation to undo.' );
		}
		$before = $this->present( $sub );
		$this->subs->update(
			(int) $sub['id'],
			array(
				'cancel_at_period_end' => false,
				'cancel_reason'        => null,
			)
		);
		$this->recordEvent( (int) $sub['id'], 'active', 'active', 'Cancellation undone' );
		$after = $this->get( (int) $sub['id'] );
		$this->audit->log( 'subscription.resumed', 'subscription', (int) $sub['id'], $before, $after );
		$this->afterChange( $after, 'active', 'active' );
		return $after;
	}

	/** @param array<string,mixed> $sub */
	public function expireCancelled( array $sub ): array {
		$sub = $this->subs->findOrFail( (int) $sub['id'] );
		if ( 'active' !== $sub['status'] || ! $sub['cancel_at_period_end'] ) {
			return $this->present( $sub );
		}
		$this->transition( $sub, 'cancelled', 'Cancelled at period end', array( 'cancelled_at' => Clock::mysql() ) );
		$after = $this->get( (int) $sub['id'] );
		$this->notifier()->member( (int) $sub['member_id'], 'expired', $this->payload( $after ), 'expired', 'Membership ended', 'Your membership has ended. Rejoin any time.' );
		return $after;
	}

	/* ---------------------------------------------------------------------
	 * Admin overrides
	 * ------------------------------------------------------------------- */

	/** @param array<string,mixed> $sub */
	public function extend( array $sub, int $days, string $reason ): array {
		$this->requireReason( $reason );
		if ( $days < 1 || $days > 3650 ) {
			throw DomainError::validation( array( 'days' => 'Days must be between 1 and 3650.' ) );
		}
		$sub    = $this->subs->findOrFail( (int) $sub['id'] );
		$before = $this->present( $sub );
		$base   = Clock::parse( $sub['current_period_end'] ) ?? Clock::now();
		if ( $base < Clock::now() && in_array( $sub['status'], array( 'suspended', 'expired' ), true ) ) {
			$base = Clock::now();
		}
		$end = $base->modify( '+' . $days . ' days' );
		$this->orders->insert(
			array(
				'member_id'       => (int) $sub['member_id'],
				'plan_id'         => (int) $sub['plan_id'],
				'subscription_id' => (int) $sub['id'],
				'type'            => 'manual_adjust',
				'currency'        => (string) $sub['currency'],
				'amount_minor'    => 0,
				'status'          => 'paid',
				'paid_at'         => Clock::mysql(),
				'period_start'    => Clock::mysql( $base ),
				'period_end'      => Clock::mysql( $end ),
				'description'     => sprintf( 'Extension of %d days: %s', $days, $reason ),
			)
		);
		$fields = array(
			'current_period_end' => Clock::mysql( $end ),
			'grace_ends_at'      => null,
		);
		if ( ! $sub['current_period_start'] ) {
			$fields['current_period_start'] = Clock::mysql();
		}
		if ( in_array( $sub['status'], array( 'past_due', 'suspended', 'expired' ), true ) ) {
			$fields['suspended_at'] = null;
			$fields['expires_at']   = null;
			$this->transition( $sub, 'active', 'Extended by admin: ' . $reason, $fields );
		} else {
			$this->subs->update( (int) $sub['id'], $fields );
			$this->recordEvent( (int) $sub['id'], (string) $sub['status'], (string) $sub['status'], sprintf( 'Extended %d days: %s', $days, $reason ) );
			$this->afterChange( $this->get( (int) $sub['id'] ), (string) $sub['status'], (string) $sub['status'] );
		}
		$after = $this->get( (int) $sub['id'] );
		$this->audit->log( 'subscription.extended', 'subscription', (int) $sub['id'], $before, $after, $reason );
		$this->notifier()->member( (int) $sub['member_id'], 'admin_extension', $this->payload( $after ) + array( 'days' => $days ), 'admin_extension', 'Membership extended', sprintf( 'We added %d days to your membership.', $days ) );
		return $after;
	}

	/** @param array<string,mixed> $sub */
	public function adminSuspend( array $sub, string $reason ): array {
		$this->requireReason( $reason );
		$after = $this->suspend( $sub, 'Suspended by admin: ' . $reason, false );
		$this->audit->log( 'subscription.admin_suspended', 'subscription', (int) $sub['id'], null, $after, $reason );
		return $after;
	}

	/** @param array<string,mixed> $sub */
	public function adminReactivate( array $sub, string $reason ): array {
		$this->requireReason( $reason );
		$sub = $this->subs->findOrFail( (int) $sub['id'] );
		if ( ! in_array( $sub['status'], array( 'suspended', 'expired', 'past_due' ), true ) ) {
			throw DomainError::conflict( 'Only past-due, suspended or expired memberships can be reactivated.' );
		}
		$this->transition(
			$sub,
			'active',
			'Reactivated by admin: ' . $reason,
			array(
				'suspended_at'  => null,
				'expires_at'    => null,
				'grace_ends_at' => null,
			)
		);
		$after = $this->get( (int) $sub['id'] );
		$this->audit->log( 'subscription.admin_reactivated', 'subscription', (int) $sub['id'], $this->present( $sub ), $after, $reason );
		$this->notifier()->member( (int) $sub['member_id'], 'restored', $this->payload( $after ), 'restored', 'Membership reactivated', 'Your access has been restored.' );
		return $after;
	}

	/* ---------------------------------------------------------------------
	 * Lifecycle scheduling
	 * ------------------------------------------------------------------- */

	/** (Re)schedule Action Scheduler jobs for a subscription. */
	public function scheduleLifecycle( array $sub ): void {
		$id = (int) $sub['id'];
		$this->scheduler->clearFor( $id );
		$status = (string) $sub['status'];
		$now    = Clock::now()->getTimestamp();
		if ( ! empty( $sub['organisation_id'] ) || ( $sub['plan']['is_free'] ?? false ) ) {
			return;
		}
		$end = Clock::timestamp( $sub['current_period_end_raw'] ?? null );
		if ( 'active' === $status && $end ) {
			if ( ! empty( $sub['cancel_at_period_end'] ) || ! Periods::isRecurring( $sub['plan'] ?? array() ) ) {
				$this->scheduler->at( max( $now, $end ), Scheduler::EXPIRE_CANCELLED, array( $id ) );
				return;
			}
			foreach ( (array) $this->settings->get( 'lifecycle', 'reminder_days', array( 7, 3 ) ) as $days ) {
				$at = $end - $this->settings->lifecycleSeconds( (int) $days );
				if ( $at > $now ) {
					$this->scheduler->at( $at, Scheduler::REMINDER, array( $id, (int) $days ) );
				}
			}
			$this->scheduler->at( max( $now, $end ), Scheduler::RENEWAL_DUE, array( $id ) );
		} elseif ( 'past_due' === $status ) {
			$grace = Clock::timestamp( $sub['grace_ends_at_raw'] ?? null ) ?? $now;
			$this->scheduler->at( max( $now, $grace ), Scheduler::END_GRACE, array( $id ) );
			$attempt = (int) ( $sub['autocharge_attempts'] ?? 0 );
			$retries = (array) $this->settings->get( 'lifecycle', 'autocharge_retry_days', array( 1, 3, 5 ) );
			if ( ! empty( $sub['payment_method_id'] ) && isset( $retries[ $attempt ] ) && $end ) {
				$at = $end + $this->settings->lifecycleSeconds( (int) $retries[ $attempt ] );
				if ( $at < $grace ) {
					$this->scheduler->at( max( $now, $at ), Scheduler::RETRY_AUTOCHARGE, array( $id, $attempt + 1 ) );
				}
			}
		} elseif ( 'suspended' === $status ) {
			$exp = Clock::timestamp( $sub['expires_at_raw'] ?? null );
			if ( $exp ) {
				$this->scheduler->at( max( $now, $exp ), Scheduler::EXPIRE, array( $id ) );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------- */

	/**
	 * Apply a status transition + side effects.
	 *
	 * @param array<string,mixed> $sub
	 * @param array<string,mixed> $fields
	 */
	public function transition( array $sub, string $to, string $reason, array $fields = array() ): void {
		$from = (string) $sub['status'];
		StateMachine::assert( $from, $to );
		$before = $this->present( $sub );
		Db::transaction(
			function () use ( $sub, $to, $fields, $from, $reason ): void {
				$this->subs->update( (int) $sub['id'], array_merge( $fields, array( 'status' => $to ) ) );
				$this->recordEvent( (int) $sub['id'], $from, $to, $reason );
			}
		);
		$after = $this->get( (int) $sub['id'] );
		$this->audit->log( 'subscription.' . $to, 'subscription', (int) $sub['id'], $before, $after, $reason );
		$this->afterChange( $after, $from, $to );
	}

	/** @param array<string,mixed> $after */
	private function afterChange( array $after, string $from, string $to ): void {
		wvm( \WitsValley\Members\Domain\Access\LevelResolver::class )->flush();
		$this->scheduleLifecycle( $after );
		do_action( 'wvm/subscription/changed', $after, $from, $to );
	}

	private function recordEvent( int $subId, ?string $from, string $to, string $reason ): void {
		[ $type, $actor ] = Audit::actor();
		$this->events->insert(
			array(
				'subscription_id' => $subId,
				'from_status'     => $from,
				'to_status'       => $to,
				'reason'          => mb_substr( $reason, 0, 255 ),
				'actor_type'      => $type,
				'actor_id'        => $actor,
			)
		);
	}

	/**
	 * New period anchored to the previous period end; if that would already be over (long lapse), start now.
	 *
	 * @param array<string,mixed> $sub
	 * @param array<string,mixed> $plan
	 * @param array<string,mixed> $order
	 * @return array{0:DateTimeImmutable,1:?DateTimeImmutable}
	 */
	private function anchoredPeriod( array $sub, array $plan, array $order ): array {
		$now = Clock::now();
		if ( ! empty( $order['period_start'] ) && ! empty( $order['period_end'] ) && (string) $order['period_start'] === (string) $sub['current_period_end'] ) {
			$start = Clock::parse( $order['period_start'] );
			$end   = Clock::parse( $order['period_end'] );
			if ( $start && $end && $end > $now ) {
				return array( $start, $end );
			}
		}
		$prev_end = Clock::parse( $sub['current_period_end'] );
		if ( $prev_end ) {
			$end = Periods::next( $prev_end, $plan );
			if ( $end && $end > $now ) {
				return array( $prev_end, $end );
			}
		}
		return array( $now, Periods::next( $now, $plan ) );
	}

	/** @param array<string,mixed> $sub */
	public function withinRejoinWindow( array $sub ): bool {
		$ref = Clock::parse( $sub['expires_at'] ?? null ) ?? Clock::parse( $sub['updated_at'] ?? null );
		if ( ! $ref ) {
			return true;
		}
		$window = (int) $this->settings->get( 'lifecycle', 'rejoin_window_days', 365 );
		return Clock::now() <= $ref->modify( '+' . $window . ' days' );
	}

	private function supersedeOthers( int $memberId, int $keepId, ?int $orgId ): void {
		if ( $orgId ) {
			return;
		}
		foreach ( $this->subs->where(
			array(
				'member_id'       => $memberId,
				'organisation_id' => null,
				'status'          => array( 'active', 'past_due', 'suspended', 'expired' ),
			)
		) as $other ) {
			if ( (int) $other['id'] === $keepId ) {
				continue;
			}
			$to = 'expired' === $other['status'] ? null : 'cancelled';
			if ( $to ) {
				$this->transition(
					$other,
					'cancelled',
					'Superseded by subscription #' . $keepId,
					array(
						'cancelled_at'  => Clock::mysql(),
						'cancel_reason' => 'superseded',
					)
				);
			}
		}
	}

	private function isFirstPaid( int $memberId ): bool {
		global $wpdb;
		$t = Db::table( 'subscriptions' );
		$p = Db::table( 'plans' );
		return 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} s INNER JOIN {$p} p ON p.id = s.plan_id WHERE s.member_id = %d AND p.is_free = 0 AND s.started_at IS NOT NULL", $memberId ) ); // phpcs:ignore WordPress.DB
	}

	private function requireReason( string $reason ): void {
		if ( '' === trim( $reason ) ) {
			throw DomainError::validation( array( 'reason' => 'A reason is required for this action.' ) );
		}
	}

	private function defaultCurrency(): string {
		$enabled = (array) $this->settings->get( 'payments', 'currencies_enabled', array( 'UGX' ) );
		return (string) ( $enabled[0] ?? 'UGX' );
	}

	private function notifier(): Notifier {
		return wvm( Notifier::class );
	}

	private function orderService(): OrderService {
		return wvm( OrderService::class );
	}

	/**
	 * Template payload for a subscription.
	 *
	 * @param array<string,mixed> $sub Presented subscription.
	 * @return array<string,mixed>
	 */
	public function payload( array $sub ): array {
		return array(
			'plan'            => $sub['plan']['name'] ?? '',
			'amount'          => $sub['amount_display'] ?? '',
			'renewal_date'    => $sub['period_end_display'] ?? '',
			'grace_end_date'  => $sub['grace_ends_display'] ?? '',
			'subscription_id' => $sub['id'],
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public function present( array $row ): array {
		$plan    = $this->plans->get( (int) $row['plan_id'] );
		$pending = $row['pending_plan_id'] ? $this->plans->get( (int) $row['pending_plan_id'] ) : null;
		$member  = $this->members->find( (int) $row['member_id'] );
		$tz      = (string) ( $member['timezone'] ?? 'Africa/Kampala' );
		$end     = Clock::parse( $row['current_period_end'] );
		return array(
			'id'                       => (int) $row['id'],
			'member_id'                => (int) $row['member_id'],
			'plan_id'                  => (int) $row['plan_id'],
			'plan'                     => array(
				'id'       => $plan['id'],
				'slug'     => $plan['slug'],
				'name'     => $plan['name'],
				'level'    => $plan['level'],
				'interval' => $plan['interval'],
				'is_free'  => $plan['is_free'],
				'benefits' => $plan['benefits'],
			),
			'pending_plan'             => $pending ? array(
				'id'   => $pending['id'],
				'name' => $pending['name'],
			) : null,
			'organisation_id'          => $row['organisation_id'] ? (int) $row['organisation_id'] : null,
			'status'                   => (string) $row['status'],
			'currency'                 => (string) $row['currency'],
			'amount_minor'             => (int) $row['amount_minor'],
			'amount_display'           => Money::format( (int) $row['amount_minor'], (string) $row['currency'] ),
			'started_at'               => Clock::iso( $row['started_at'] ),
			'current_period_start'     => Clock::iso( $row['current_period_start'] ),
			'current_period_end'       => Clock::iso( $row['current_period_end'] ),
			'current_period_end_raw'   => $row['current_period_end'],
			'period_end_display'       => Clock::inZone( $row['current_period_end'], $tz ),
			'days_left'                => $end ? max( 0, (int) ceil( ( $end->getTimestamp() - Clock::now()->getTimestamp() ) / DAY_IN_SECONDS ) ) : null,
			'grace_ends_at'            => Clock::iso( $row['grace_ends_at'] ),
			'grace_ends_at_raw'        => $row['grace_ends_at'],
			'grace_ends_display'       => Clock::inZone( $row['grace_ends_at'], $tz ),
			'suspended_at'             => Clock::iso( $row['suspended_at'] ),
			'expires_at'               => Clock::iso( $row['expires_at'] ),
			'expires_at_raw'           => $row['expires_at'],
			'cancel_at_period_end'     => (bool) $row['cancel_at_period_end'],
			'cancelled_at'             => Clock::iso( $row['cancelled_at'] ),
			'cancel_reason'            => $row['cancel_reason'],
			'payment_method_id'        => $row['payment_method_id'] ? (int) $row['payment_method_id'] : null,
			'autocharge_attempts'      => (int) $row['autocharge_attempts'],
			'previous_subscription_id' => $row['previous_subscription_id'] ? (int) $row['previous_subscription_id'] : null,
			'can_rejoin'               => 'expired' === $row['status'] ? $this->withinRejoinWindow( $row ) : false,
			'created_at'               => Clock::iso( $row['created_at'] ),
			'updated_at'               => Clock::iso( $row['updated_at'] ),
		);
	}
}
