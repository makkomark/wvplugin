<?php
declare( strict_types=1 );

namespace WitsValley\Members\Jobs;

/**
 * Thin wrapper over Action Scheduler (bundled with WooCommerce). Group: wv-members.
 */
final class Scheduler {

	public const GROUP = 'wv-members';

	public const REMINDER          = 'wvm_job_renewal_reminder';
	public const RENEWAL_DUE       = 'wvm_job_renewal_due';
	public const END_GRACE         = 'wvm_job_end_grace';
	public const EXPIRE            = 'wvm_job_expire_subscription';
	public const RETRY_AUTOCHARGE  = 'wvm_job_retry_autocharge';
	public const EXPIRE_CANCELLED  = 'wvm_job_expire_cancelled';
	public const CANCEL_STALE      = 'wvm_job_cancel_stale_orders';
	public const DAILY_RECONCILE   = 'wvm_job_daily_reconcile';
	public const SEND_EMAIL        = 'wvm_job_send_email';
	public const KIT_SYNC          = 'wvm_job_kit_sync_member';
	public const KIT_TAG           = 'wvm_job_kit_add_tag';
	public const KIT_RECONCILE     = 'wvm_job_kit_reconcile';
	public const WORKSHOP_REMINDER = 'wvm_job_workshop_reminder';
	public const HEALTH            = 'wvm_job_health_check';
	public const BACKUP            = 'wvm_job_backup';
	public const ORG_EXPIRE        = 'wvm_job_org_expire';
	public const TEST_GATEWAY      = 'wvm_job_test_gateway_complete';
	public const PENDING_NUDGE     = 'wvm_job_payment_pending_nudge';

	/** Per-subscription hooks cleared on reschedule. */
	public const SUBSCRIPTION_HOOKS = array( self::REMINDER, self::RENEWAL_DUE, self::END_GRACE, self::EXPIRE, self::RETRY_AUTOCHARGE, self::EXPIRE_CANCELLED );

	/** Recurring daily jobs (hook => hour UTC). */
	public const DAILY = array(
		self::CANCEL_STALE    => 1,
		self::DAILY_RECONCILE => 2,
		self::KIT_RECONCILE   => 3,
		self::HEALTH          => 4,
		self::BACKUP          => 0,
		self::ORG_EXPIRE      => 5,
	);

	/** @param list<int|string> $args */
	public function at( int $timestamp, string $hook, array $args = array() ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
		}
	}

	/** @param list<int|string> $args */
	public function async( string $hook, array $args = array() ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $args, self::GROUP );
		} elseif ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), $hook, $args, self::GROUP );
		}
	}

	/** @param list<int|string> $args */
	public function unique( string $hook, array $args = array(), int $delay = 0 ): void {
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
			return;
		}
		$delay > 0 ? $this->at( time() + $delay, $hook, $args ) : $this->async( $hook, $args );
	}

	public function clearFor( int $subscriptionId ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		foreach ( self::SUBSCRIPTION_HOOKS as $hook ) {
			as_unschedule_all_actions( $hook, array( $subscriptionId ), self::GROUP );
			if ( self::REMINDER === $hook || self::RETRY_AUTOCHARGE === $hook ) {
				// Hooks with a second arg: search and cancel pending actions by first arg.
				$this->unscheduleByFirstArg( $hook, $subscriptionId );
			}
		}
	}

	private function unscheduleByFirstArg( string $hook, int $first ): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return;
		}
		$ids   = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 50,
			),
			'ids'
		);
		$store = \ActionScheduler::store();
		foreach ( $ids as $id ) {
			$action = $store->fetch_action( (int) $id );
			$args   = $action->get_args();
			if ( isset( $args[0] ) && (int) $args[0] === $first ) {
				$store->cancel_action( (int) $id );
			}
		}
	}

	public function ensureRecurring(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		foreach ( self::DAILY as $hook => $hour ) {
			if ( ! as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
				$first = strtotime( gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) . sprintf( ' %02d:15:00', $hour ) );
				as_schedule_recurring_action( (int) $first, DAY_IN_SECONDS, $hook, array(), self::GROUP );
			}
		}
	}

	public function clearAll(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}

	/** Number of failed actions in our group in the last 24h. */
	public function failedCount(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		return count(
			as_get_scheduled_actions(
				array(
					'group'        => self::GROUP,
					'status'       => \ActionScheduler_Store::STATUS_FAILED,
					'date'         => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
					'date_compare' => '>=',
					'per_page'     => 200,
				),
				'ids'
			)
		);
	}

	public function pendingCount(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		return count(
			as_get_scheduled_actions(
				array(
					'group'    => self::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1000,
				),
				'ids'
			)
		);
	}
}
