<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Order;

use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Member\MemberService;
use WitsValley\Members\Domain\Notification\Notifier;
use WitsValley\Members\Domain\Payment\PaymentRepository;
use WitsValley\Members\Domain\Plan\PlanService;
use WitsValley\Members\Domain\Subscription\SubscriptionRepository;
use WitsValley\Members\Domain\Subscription\SubscriptionService;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Encryption;
use WitsValley\Members\Support\Money;
use WitsValley\Members\Support\Pagination;
use WitsValley\Members\Support\Settings;

/**
 * Plugin orders, bridged to WooCommerce orders for payment.
 */
final class OrderService {

	public const TYPES    = array( 'new', 'renewal', 'upgrade', 'downgrade', 'reactivation', 'enterprise', 'manual_adjust' );
	public const STATUSES = array( 'pending', 'paid', 'failed', 'cancelled', 'refunded', 'needs_review' );
	public const META     = '_wvm_order_id';

	public function __construct(
		private OrderRepository $orders,
		private PaymentRepository $payments,
		private HookInboxRepository $inbox,
		private SubscriptionRepository $subs,
		private MemberService $members,
		private PlanService $plans,
		private Audit $audit,
		private Settings $settings
	) {}

	/**
	 * Create a pending plugin order + Woo order for a plan.
	 *
	 * @return array<string,mixed> Presented order incl. checkout_url and pay_link.
	 */
	public function createForPlan(
		int $memberId,
		int $planId,
		string $type,
		string $currency,
		?string $periodStart = null,
		?string $periodEnd = null,
		?int $subscriptionId = null,
		?int $amountOverride = null,
		?string $description = null,
		?int $orgId = null
	): array {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw DomainError::validation( array( 'type' => 'Invalid order type.' ) );
		}
		$currency = strtoupper( $currency );
		$this->assertCurrencyEnabled( $currency );
		$plan   = $this->plans->get( $planId );
		$amount = $amountOverride ?? ( $plan['prices'][ $currency ] ?? null );
		if ( null === $amount ) {
			throw DomainError::validation( array( 'currency' => sprintf( '%s is not priced in %s.', $plan['name'], $currency ) ) );
		}
		if ( $plan['is_free'] ) {
			throw DomainError::validation( array( 'plan_id' => 'The free plan does not need an order.' ) );
		}
		$member = $this->members->get( $memberId );

		$id    = Db::transaction(
			function () use ( $memberId, $planId, $type, $currency, $amount, $periodStart, $periodEnd, $subscriptionId, $description, $orgId, $plan ): int {
				if ( 'new' === $type && ! $subscriptionId ) {
					$subscriptionId = wvm( SubscriptionService::class )->createPending( $memberId, $planId, $currency, (int) $amount, $orgId );
				}
				return $this->orders->insert(
					array(
						'member_id'       => $memberId,
						'plan_id'         => $planId,
						'subscription_id' => $subscriptionId,
						'organisation_id' => $orgId,
						'type'            => $type,
						'currency'        => $currency,
						'amount_minor'    => (int) $amount,
						'status'          => 'pending',
						'period_start'    => $periodStart,
						'period_end'      => $periodEnd,
						'pay_link_token'  => Encryption::token( 16 ),
						'description'     => $description ?? sprintf( 'WitsValley %s — %s', $plan['name'], self::typeLabel( $type ) ),
					)
				);
			}
		);
		$wc_id = $this->createWooOrder( $id, $member, $plan, $currency, (int) $amount, (string) $this->orders->findOrFail( $id )['description'] );
		$this->orders->update( $id, array( 'woo_order_id' => $wc_id ) );
		$order = $this->get( $id );
		$this->audit->log( 'order.created', 'order', $id, null, $order );
		do_action( 'wvm/order/created', $order );
		return $order;
	}

	/**
	 * Enterprise invoice order (no plan product, single fee line).
	 *
	 * @return array<string,mixed>
	 */
	public function createInvoice( int $memberId, int $orgId, int $amountMinor, string $currency, string $description, ?int $planId = null ): array {
		$currency = strtoupper( $currency );
		$member   = $this->members->get( $memberId );
		$id       = $this->orders->insert(
			array(
				'member_id'       => $memberId,
				'plan_id'         => $planId,
				'organisation_id' => $orgId,
				'type'            => 'enterprise',
				'currency'        => $currency,
				'amount_minor'    => $amountMinor,
				'status'          => 'pending',
				'pay_link_token'  => Encryption::token( 16 ),
				'description'     => mb_substr( $description, 0, 255 ),
			)
		);
		$wc_id    = $this->createWooOrder( $id, $member, null, $currency, $amountMinor, $description );
		$this->orders->update( $id, array( 'woo_order_id' => $wc_id ) );
		$order = $this->get( $id );
		$this->audit->log( 'order.invoice_created', 'order', $id, null, $order );
		return $order;
	}

	/**
	 * @param array<string,mixed>      $member
	 * @param array<string,mixed>|null $plan
	 */
	private function createWooOrder( int $orderId, array $member, ?array $plan, string $currency, int $amountMinor, string $description ): int {
		$wc = wc_create_order(
			array(
				'customer_id' => (int) $member['user_id'],
				'created_via' => 'wv-members',
			)
		);
		if ( is_wp_error( $wc ) ) {
			throw new DomainError( 'Could not create the payment order: ' . $wc->get_error_message(), 'wvm_woo_error', 500 );
		}
		$major   = Money::toMajor( $amountMinor, $currency );
		$product = ( $plan && $plan['woo_product_id'] ) ? wc_get_product( (int) $plan['woo_product_id'] ) : null;
		if ( $product ) {
			$wc->add_product(
				$product,
				1,
				array(
					'name'     => $description,
					'subtotal' => $major,
					'total'    => $major,
				)
			);
		} else {
			$fee = new \WC_Order_Item_Fee();
			$fee->set_name( $description );
			$fee->set_amount( (string) $major );
			$fee->set_total( (string) $major );
			$fee->set_tax_status( 'none' );
			$wc->add_item( $fee );
		}
		$wc->set_currency( $currency );
		$wc->set_billing_first_name( (string) $member['first_name'] );
		$wc->set_billing_last_name( (string) $member['last_name'] );
		$wc->set_billing_email( (string) $member['email'] );
		$wc->set_billing_phone( (string) $member['phone'] );
		$wc->set_billing_country( (string) ( $member['country'] ?: 'UG' ) );
		$wc->update_meta_data( self::META, $orderId );
		$wc->calculate_totals( false );
		$wc->set_total( (string) $major );
		$wc->save();
		return (int) $wc->get_id();
	}

	/** @return array<string,mixed> */
	public function get( int $id ): array {
		return $this->present( $this->orders->findOrFail( $id, 'order' ) );
	}

	/** @return array<string,mixed> */
	public function raw( int $id ): array {
		return $this->orders->findOrFail( $id, 'order' );
	}

	/** @return array<string,mixed>|null */
	public function byToken( string $token ): ?array {
		return $this->orders->byToken( $token );
	}

	/**
	 * @param array<string,mixed> $f status,gateway,type,member,from,to,q
	 * @return array{items:list<array<string,mixed>>, meta:array<string,int>}
	 */
	public function list( array $f, Pagination $p ): array {
		$cond = array();
		$args = array();
		foreach ( array( 'status', 'type' ) as $key ) {
			if ( ! empty( $f[ $key ] ) ) {
				$cond[] = "o.{$key} = %s";
				$args[] = (string) $f[ $key ];
			}
		}
		if ( ! empty( $f['member'] ) ) {
			$cond[] = 'o.member_id = %d';
			$args[] = (int) $f['member'];
		}
		if ( ! empty( $f['organisation'] ) ) {
			$cond[] = 'o.organisation_id = %d';
			$args[] = (int) $f['organisation'];
		}
		if ( ! empty( $f['gateway'] ) ) {
			$cond[] = 'py.gateway = %s';
			$args[] = (string) $f['gateway'];
		}
		if ( ! empty( $f['currency'] ) ) {
			$cond[] = 'o.currency = %s';
			$args[] = strtoupper( (string) $f['currency'] );
		}
		if ( ! empty( $f['from'] ) ) {
			$cond[] = 'o.created_at >= %s';
			$args[] = gmdate( 'Y-m-d 00:00:00', (int) strtotime( (string) $f['from'] ) );
		}
		if ( ! empty( $f['to'] ) ) {
			$cond[] = 'o.created_at <= %s';
			$args[] = gmdate( 'Y-m-d 23:59:59', (int) strtotime( (string) $f['to'] ) );
		}
		if ( ! empty( $f['q'] ) ) {
			global $wpdb;
			$like   = '%' . $wpdb->esc_like( (string) $f['q'] ) . '%';
			$m      = Db::table( 'members' );
			$cond[] = "(o.receipt_number LIKE %s OR py.provider_ref LIKE %s OR o.woo_order_id = %d OR o.member_id IN (SELECT mm.id FROM {$m} mm INNER JOIN {$wpdb->users} uu ON uu.ID = mm.user_id WHERE uu.user_email LIKE %s OR uu.display_name LIKE %s OR mm.public_id LIKE %s))";
			array_push( $args, $like, $like, (int) $f['q'], $like, $like, $like );
		}
		$py   = Db::table( 'payments' );
		$join = "LEFT JOIN {$py} py ON py.id = (SELECT MAX(p2.id) FROM {$py} p2 WHERE p2.order_id = o.id)";
		$repo = $this->orders;
		global $wpdb;
		$t         = $repo->tableName();
		$where     = $cond ? 'WHERE ' . implode( ' AND ', $cond ) : '';
		$count_sql = "SELECT COUNT(*) FROM {$t} o {$join} {$where}";
		$total     = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql ); // phpcs:ignore WordPress.DB
		$sql       = "SELECT o.*, py.gateway AS pay_gateway, py.provider_ref AS pay_ref FROM {$t} o {$join} {$where} ORDER BY o.id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $p->perPage, $p->offset() ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$items     = array();
		foreach ( $rows ?: array() as $row ) {
			$items[] = $this->present( $repo->hydrate( $row ) );
		}
		return array(
			'items' => $items,
			'meta'  => $p->meta( $total ),
		);
	}

	/** @return array<string,mixed> Detail incl. payments + hook inbox. */
	public function detail( int $id ): array {
		$order             = $this->get( $id );
		$order['payments'] = array_map(
			static fn( array $p ): array => array(
				'id'             => (int) $p['id'],
				'gateway'        => $p['gateway'],
				'provider_ref'   => $p['provider_ref'],
				'amount_minor'   => (int) $p['amount_minor'],
				'amount_display' => Money::format( (int) $p['amount_minor'], (string) $p['currency'] ),
				'currency'       => $p['currency'],
				'status'         => $p['status'],
				'confirmed_at'   => Clock::iso( $p['confirmed_at'] ),
				'created_at'     => Clock::iso( $p['created_at'] ),
			),
			$this->payments->where( array( 'order_id' => $id ) )
		);
		$order['hook_log'] = $order['woo_order_id'] ? array_map(
			static fn( array $h ): array => array(
				'id'           => (int) $h['id'],
				'event'        => $h['event'],
				'result'       => $h['result'],
				'processed_at' => Clock::iso( $h['processed_at'] ),
				'created_at'   => Clock::iso( $h['created_at'] ),
			),
			$this->inbox->where( array( 'woo_order_id' => (int) $order['woo_order_id'] ) )
		) : array();
		return $order;
	}

	/** @return array<string,mixed> */
	public function cancel( int $id, string $reason ): array {
		$order = $this->raw( $id );
		if ( 'pending' !== $order['status'] && 'failed' !== $order['status'] ) {
			throw DomainError::conflict( 'Only pending or failed orders can be cancelled.' );
		}
		$before = $this->present( $order );
		$this->orders->update(
			$id,
			array(
				'status'        => 'cancelled',
				'review_reason' => $reason ? mb_substr( $reason, 0, 255 ) : null,
			)
		);
		if ( $order['woo_order_id'] ) {
			$wc = wc_get_order( (int) $order['woo_order_id'] );
			if ( $wc && ! $wc->has_status( array( 'cancelled', 'completed', 'processing', 'refunded' ) ) ) {
				$wc->update_status( 'cancelled', 'WitsValley: ' . $reason );
			}
		}
		$this->cancelPendingSubscription( $order, 'Order cancelled: ' . $reason );
		$after = $this->get( $id );
		$this->audit->log( 'order.cancelled', 'order', $id, $before, $after, $reason );
		return $after;
	}

	/** @return array<string,mixed> */
	public function markFailed( int $id, string $reason = 'Payment failed' ): array {
		$order = $this->raw( $id );
		if ( 'pending' !== $order['status'] ) {
			return $this->present( $order );
		}
		$this->orders->update(
			$id,
			array(
				'status'        => 'failed',
				'review_reason' => $reason,
			)
		);
		$after = $this->get( $id );
		$this->audit->log( 'order.failed', 'order', $id, $this->present( $order ), $after, $reason );
		return $after;
	}

	/** @return array<string,mixed> */
	public function markNeedsReview( int $id, string $reason ): array {
		$order = $this->raw( $id );
		$this->orders->update(
			$id,
			array(
				'status'        => 'needs_review',
				'review_reason' => mb_substr( $reason, 0, 255 ),
			)
		);
		$after = $this->get( $id );
		$this->audit->log( 'order.needs_review', 'order', $id, $this->present( $order ), $after, $reason );
		wvm( Notifier::class )->admin( 'Payment needs review', sprintf( "Order #%d (%s) needs review: %s\n\nReview it: %s", $id, $after['amount_display'], $reason, admin_url( 'admin.php?page=wvm-payments#/payments/review' ) ) );
		do_action( 'wvm/order/needs_review', $after, $reason );
		return $after;
	}

	/** Approve a review-queue order: runs activation now. */
	public function approveReview( int $id, string $reason ): array {
		$this->requireReason( $reason );
		$order = $this->raw( $id );
		if ( 'needs_review' !== $order['status'] ) {
			throw DomainError::conflict( 'This order is not awaiting review.' );
		}
		$before = $this->present( $order );
		$this->orders->update(
			$id,
			array(
				'reviewed_by' => get_current_user_id() ?: null,
				'reviewed_at' => Clock::mysql(),
			)
		);
		wvm( OrderListener::class )->activate( $id, true );
		$after = $this->get( $id );
		$this->audit->log( 'order.review_approved', 'order', $id, $before, $after, $reason );
		return $after;
	}

	/** Reject a review-queue order: refunds via Woo if money was taken. */
	public function rejectReview( int $id, string $reason ): array {
		$this->requireReason( $reason );
		$order = $this->raw( $id );
		if ( 'needs_review' !== $order['status'] ) {
			throw DomainError::conflict( 'This order is not awaiting review.' );
		}
		$before = $this->present( $order );
		$wc     = $order['woo_order_id'] ? wc_get_order( (int) $order['woo_order_id'] ) : null;
		$status = 'cancelled';
		if ( $wc && $wc->is_paid() && (float) $wc->get_total() > 0 ) {
			$refund = wc_create_refund(
				array(
					'order_id'       => $wc->get_id(),
					'amount'         => (float) $wc->get_total() - (float) $wc->get_total_refunded(),
					'reason'         => 'Review rejected: ' . $reason,
					'refund_payment' => $this->gatewaySupportsRefund( $wc ),
				)
			);
			if ( is_wp_error( $refund ) ) {
				throw new DomainError( 'Refund failed: ' . $refund->get_error_message(), 'wvm_refund_failed', 502 );
			}
			$status = 'refunded';
		} elseif ( $wc && ! $wc->has_status( array( 'cancelled', 'refunded' ) ) ) {
			$wc->update_status( 'cancelled', 'WitsValley review rejected: ' . $reason );
		}
		$this->orders->update(
			$id,
			array(
				'status'        => $status,
				'review_reason' => mb_substr( $reason, 0, 255 ),
				'reviewed_by'   => get_current_user_id() ?: null,
				'reviewed_at'   => Clock::mysql(),
			)
		);
		$this->cancelPendingSubscription( $order, 'Payment review rejected' );
		$after = $this->get( $id );
		$this->audit->log( 'order.review_rejected', 'order', $id, $before, $after, $reason );
		return $after;
	}

	/**
	 * Record an offline payment (bank, cash, mobile money outside the gateway).
	 *
	 * @return array<string,mixed>
	 */
	public function recordManual( int $memberId, int $planId, int $amountMinor, string $currency, string $method, string $reference, ?string $paidAt = null ): array {
		if ( '' === trim( $reference ) ) {
			throw DomainError::validation( array( 'reference' => 'A payment reference is required.' ) );
		}
		if ( $amountMinor <= 0 ) {
			throw DomainError::validation( array( 'amount_minor' => 'Amount must be greater than zero.' ) );
		}
		$currency = strtoupper( $currency );
		$current  = $this->subs->current( $memberId );
		$type     = 'new';
		$sub_id   = null;
		$period   = array( null, null );
		if ( $current && ! $this->plans->get( (int) $current['plan_id'] )['is_free'] ) {
			if ( in_array( $current['status'], array( 'active', 'past_due' ), true ) && (int) $current['plan_id'] === $planId ) {
				$type   = 'renewal';
				$sub_id = (int) $current['id'];
				$period = $this->nextPeriod( $current );
			} elseif ( in_array( $current['status'], array( 'suspended', 'expired' ), true ) && wvm( SubscriptionService::class )->withinRejoinWindow( $current ) ) {
				$type   = 'reactivation';
				$sub_id = (int) $current['id'];
			}
		}
		$order = $this->createForPlan( $memberId, $planId, $type, $currency, $period[0], $period[1], $sub_id, $amountMinor, null );
		$wc    = wc_get_order( (int) $order['woo_order_id'] );
		if ( ! $wc ) {
			throw new DomainError( 'Woo order missing.', 'wvm_woo_error', 500 );
		}
		$wc->set_payment_method( 'wvm_manual' );
		$wc->set_payment_method_title( 'Manual: ' . sanitize_text_field( $method ) );
		$wc->set_transaction_id( sanitize_text_field( $reference ) );
		if ( $paidAt ) {
			$wc->set_date_paid( (int) strtotime( $paidAt ) );
		}
		$wc->add_order_note( sprintf( 'Manual payment recorded by %s (%s, ref %s).', wp_get_current_user()->display_name, $method, $reference ) );
		$wc->save();
		$wc->payment_complete( sanitize_text_field( $reference ) );
		$after = $this->get( (int) $order['id'] );
		$this->audit->log( 'order.manual_recorded', 'order', (int) $order['id'], null, $after, sprintf( '%s ref %s', $method, $reference ) );
		wvm( Notifier::class )->member(
			$memberId,
			'admin_manual_payment',
			array(
				'amount'    => $after['amount_display'],
				'reference' => $reference,
				'plan'      => $after['plan_name'],
			)
		);
		return $after;
	}

	/** @return array<string,mixed> */
	public function refund( int $id, int $amountMinor, string $reason, bool $endMembership = false ): array {
		$this->requireReason( $reason );
		$order = $this->raw( $id );
		if ( ! in_array( $order['status'], array( 'paid', 'refunded' ), true ) ) {
			throw DomainError::conflict( 'Only paid orders can be refunded.' );
		}
		$wc = $order['woo_order_id'] ? wc_get_order( (int) $order['woo_order_id'] ) : null;
		if ( ! $wc ) {
			throw DomainError::conflict( 'This order has no WooCommerce payment to refund.' );
		}
		$remaining = Money::toMinor( (float) $wc->get_total() - (float) $wc->get_total_refunded(), (string) $order['currency'] );
		if ( $amountMinor <= 0 || $amountMinor > $remaining ) {
			throw DomainError::validation( array( 'amount_minor' => 'Refund must be between 1 and ' . Money::format( $remaining, (string) $order['currency'] ) . '.' ) );
		}
		$before = $this->present( $order );
		$refund = wc_create_refund(
			array(
				'order_id'       => $wc->get_id(),
				'amount'         => Money::toMajor( $amountMinor, (string) $order['currency'] ),
				'reason'         => $reason,
				'refund_payment' => $this->gatewaySupportsRefund( $wc ),
			)
		);
		if ( is_wp_error( $refund ) ) {
			throw new DomainError( 'Refund failed: ' . $refund->get_error_message(), 'wvm_refund_failed', 502 );
		}
		if ( $amountMinor === $remaining ) {
			$this->orders->update( $id, array( 'status' => 'refunded' ) );
			foreach ( $this->payments->where( array( 'order_id' => $id ) ) as $pay ) {
				$this->payments->update( (int) $pay['id'], array( 'status' => 'refunded' ) );
			}
		}
		if ( $endMembership && $order['subscription_id'] ) {
			$sub = $this->subs->find( (int) $order['subscription_id'] );
			if ( $sub && in_array( $sub['status'], array( 'active', 'past_due', 'suspended' ), true ) ) {
				wvm( SubscriptionService::class )->cancel( $sub, 'Refunded: ' . $reason, true );
			}
		}
		$after = $this->get( $id );
		$this->audit->log( 'order.refunded', 'order', $id, $before, $after + array( 'refund_minor' => $amountMinor ), $reason );
		wvm( Notifier::class )->member(
			(int) $order['member_id'],
			'admin_refund',
			array(
				'amount' => Money::format( $amountMinor, (string) $order['currency'] ),
				'reason' => $reason,
			)
		);
		return $after;
	}

	public function payLinkUrl( array $order ): string {
		return home_url( '/pay/' . $order['pay_link_token'] . '/' );
	}

	/**
	 * Find or create the pending renewal (or reactivation) order for a subscription.
	 *
	 * @param array<string,mixed> $sub Raw subscription row.
	 * @return array<string,mixed>
	 */
	public function ensureRenewalOrder( array $sub ): array {
		$type     = in_array( $sub['status'], array( 'suspended', 'expired' ), true ) ? 'reactivation' : 'renewal';
		$existing = $this->orders->pendingFor( (int) $sub['id'], array( $type ) );
		if ( $existing ) {
			return $this->present( $existing );
		}
		$plan_id  = (int) ( $sub['pending_plan_id'] ?: $sub['plan_id'] );
		$plan     = $this->plans->get( $plan_id );
		$currency = (string) $sub['currency'];
		if ( ! isset( $plan['prices'][ $currency ] ) ) {
			$currency = (string) ( array_key_first( $plan['prices'] ) ?? $currency );
		}
		$period = 'renewal' === $type ? $this->nextPeriod( $sub, $plan ) : array( null, null );
		return $this->createForPlan( (int) $sub['member_id'], $plan_id, $type, $currency, $period[0], $period[1], (int) $sub['id'] );
	}

	/**
	 * @param array<string,mixed>      $sub
	 * @param array<string,mixed>|null $plan
	 * @return array{0:?string,1:?string}
	 */
	private function nextPeriod( array $sub, ?array $plan = null ): array {
		$plan  = $plan ?? $this->plans->get( (int) $sub['plan_id'] );
		$start = Clock::parse( $sub['current_period_end'] );
		if ( ! $start ) {
			return array( null, null );
		}
		$end = \WitsValley\Members\Domain\Subscription\Periods::next( $start, $plan );
		return array( Clock::mysql( $start ), $end ? Clock::mysql( $end ) : null );
	}

	/** Mark order paid + receipt number (called by listener inside its transaction). */
	public function markPaid( int $id ): void {
		$receipt = (string) $this->settings->get( 'general', 'receipt_prefix', 'WV-R-' ) . $this->settings->increment( 'general', 'receipt_next_number' );
		$this->orders->update(
			$id,
			array(
				'status'         => 'paid',
				'paid_at'        => Clock::mysql(),
				'receipt_number' => $receipt,
			)
		);
	}

	/** Daily job: cancel pending orders older than stale_order_hours. */
	public function cancelStale(): int {
		global $wpdb;
		$hours  = (int) $this->settings->get( 'lifecycle', 'stale_order_hours', 24 );
		$cutoff = Clock::mysql( Clock::now()->modify( '-' . $hours . ' hours' ) );
		$t      = $this->orders->tableName();
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'pending' AND type IN ('new','upgrade','enterprise') AND created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB
		foreach ( $ids as $id ) {
			try {
				$this->cancel( (int) $id, 'Stale: unpaid after ' . $hours . 'h' );
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		return count( $ids );
	}

	/** @param array<string,mixed> $order */
	private function cancelPendingSubscription( array $order, string $reason ): void {
		if ( 'new' !== $order['type'] || ! $order['subscription_id'] ) {
			return;
		}
		$sub = $this->subs->find( (int) $order['subscription_id'] );
		if ( $sub && 'pending' === $sub['status'] ) {
			wvm( SubscriptionService::class )->transition( $sub, 'cancelled', $reason, array( 'cancelled_at' => Clock::mysql() ) );
		}
	}

	private function gatewaySupportsRefund( \WC_Order $wc ): bool {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gw       = $gateways[ $wc->get_payment_method() ] ?? null;
		return $gw && $gw->supports( 'refunds' );
	}

	private function assertCurrencyEnabled( string $currency ): void {
		$enabled = array_map( 'strtoupper', (array) $this->settings->get( 'payments', 'currencies_enabled', array() ) );
		if ( $enabled && ! in_array( $currency, $enabled, true ) ) {
			throw DomainError::validation( array( 'currency' => $currency . ' is not enabled.' ) );
		}
	}

	private function requireReason( string $reason ): void {
		if ( '' === trim( $reason ) ) {
			throw DomainError::validation( array( 'reason' => 'A reason is required for this action.' ) );
		}
	}

	public static function wooEditUrl( int $wooId ): string {
		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $wooId );
		}
		return admin_url( 'post.php?post=' . $wooId . '&action=edit' );
	}

	public static function typeLabel( string $type ): string {
		return match ( $type ) {
			'new'           => 'New membership',
			'renewal'       => 'Renewal',
			'upgrade'       => 'Upgrade',
			'downgrade'     => 'Downgrade',
			'reactivation'  => 'Reactivation',
			'enterprise'    => 'Enterprise invoice',
			'manual_adjust' => 'Adjustment',
			default         => ucfirst( $type ),
		};
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public function present( array $row, bool $withMember = true ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity
		$plan   = $row['plan_id'] ? $this->plans->get( (int) $row['plan_id'] ) : null;
		$member = null;
		if ( $withMember ) {
			try {
				$m      = $this->members->get( (int) $row['member_id'] );
				$member = array(
					'id'        => $m['id'],
					'public_id' => $m['public_id'],
					'name'      => $m['name'],
					'email'     => $m['email'],
				);
			} catch ( DomainError $e ) {
				$member = null;
			}
		}
		$payment_gateway = $row['pay_gateway'] ?? null;
		if ( null === $payment_gateway && $row['woo_order_id'] && function_exists( 'wc_get_order' ) ) {
			$wc              = wc_get_order( (int) $row['woo_order_id'] );
			$payment_gateway = $wc ? ( $wc->get_payment_method() ?: null ) : null;
		}
		$token    = $row['pay_link_token'] ?? null;
		$is_open  = in_array( $row['status'], array( 'pending', 'failed' ), true );
		$checkout = null;
		if ( $is_open && $row['woo_order_id'] && function_exists( 'wc_get_order' ) ) {
			$wc       = wc_get_order( (int) $row['woo_order_id'] );
			$checkout = $wc ? $wc->get_checkout_payment_url() : null;
		}
		return array(
			'id'              => (int) $row['id'],
			'woo_order_id'    => $row['woo_order_id'] ? (int) $row['woo_order_id'] : null,
			'woo_order_url'   => $row['woo_order_id'] ? self::wooEditUrl( (int) $row['woo_order_id'] ) : null,
			'member_id'       => (int) $row['member_id'],
			'member'          => $member,
			'plan_id'         => $row['plan_id'] ? (int) $row['plan_id'] : null,
			'plan_name'       => $plan['name'] ?? '—',
			'subscription_id' => $row['subscription_id'] ? (int) $row['subscription_id'] : null,
			'organisation_id' => $row['organisation_id'] ? (int) $row['organisation_id'] : null,
			'type'            => (string) $row['type'],
			'type_label'      => self::typeLabel( (string) $row['type'] ),
			'currency'        => (string) $row['currency'],
			'amount_minor'    => (int) $row['amount_minor'],
			'amount_display'  => Money::format( (int) $row['amount_minor'], (string) $row['currency'] ),
			'status'          => (string) $row['status'],
			'description'     => (string) ( $row['description'] ?? '' ),
			'gateway'         => $payment_gateway,
			'provider_ref'    => $row['pay_ref'] ?? null,
			'period_start'    => Clock::iso( $row['period_start'] ),
			'period_end'      => Clock::iso( $row['period_end'] ),
			'receipt_number'  => $row['receipt_number'] ?? null,
			'receipt_url'     => 'paid' === $row['status'] || 'refunded' === $row['status'] ? rest_url( WVM_REST_NS . '/me/orders/' . (int) $row['id'] . '/receipt.pdf' ) : null,
			'pay_link'        => $is_open && $token ? home_url( '/pay/' . $token . '/' ) : null,
			'pay_link_token'  => $is_open ? $token : null,
			'checkout_url'    => $checkout,
			'review_reason'   => $row['review_reason'] ?? null,
			'reviewed_at'     => Clock::iso( $row['reviewed_at'] ?? null ),
			'paid_at'         => Clock::iso( $row['paid_at'] ?? null ),
			'created_at'      => Clock::iso( $row['created_at'] ),
		);
	}
}
