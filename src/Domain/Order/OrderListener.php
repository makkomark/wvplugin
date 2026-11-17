<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Order;

use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Notification\Notifier;
use WitsValley\Members\Domain\Payment\PaymentRepository;
use WitsValley\Members\Domain\Plan\PlanService;
use WitsValley\Members\Domain\Subscription\SubscriptionRepository;
use WitsValley\Members\Domain\Subscription\SubscriptionService;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Money;
use WitsValley\Members\Support\Settings;

/**
 * Turns WooCommerce order-status events into membership changes.
 *
 * 1. Every event → hook_inbox. 2. Ignore non-plugin orders. 3. Idempotent (paid = no-op).
 * 4. Amount + currency verified → else needs_review. 5. payments row. 6. Dispatch by type in one transaction.
 */
final class OrderListener {

	public const EVENTS = array(
		'woocommerce_order_status_processing' => 'processing',
		'woocommerce_order_status_completed'  => 'completed',
		'woocommerce_payment_complete'        => 'payment_complete',
		'woocommerce_order_status_failed'     => 'failed',
		'woocommerce_order_status_cancelled'  => 'cancelled',
		'woocommerce_order_refunded'          => 'refunded',
	);

	public function __construct(
		private OrderRepository $orders,
		private HookInboxRepository $inbox,
		private PaymentRepository $payments,
		private SubscriptionRepository $subs,
		private PlanService $plans,
		private Settings $settings
	) {}

	public function register(): void {
		foreach ( self::EVENTS as $hook => $event ) {
			add_action(
				$hook,
				function ( $wooId ) use ( $event ): void {
					$this->handle( $event, (int) $wooId );
				},
				20,
				1
			);
		}
	}

	public function handle( string $event, int $wooId ): string {
		$wc      = wc_get_order( $wooId );
		$inboxId = $this->inbox->insert(
			array(
				'woo_order_id' => $wooId,
				'event'        => $event,
				'payload'      => $wc ? array(
					'status'         => $wc->get_status(),
					'total'          => $wc->get_total(),
					'currency'       => $wc->get_currency(),
					'payment_method' => $wc->get_payment_method(),
					'transaction_id' => $wc->get_transaction_id(),
				) : null,
			)
		);
		$result = 'ignored';
		try {
			$result = Audit::asSystem( fn() => $this->dispatch( $event, $wooId ) );
		} catch ( \Throwable $e ) {
			$result = 'error';
			error_log( 'WVM listener error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		$this->inbox->update(
			$inboxId,
			array(
				'processed_at' => Clock::mysql(),
				'result'       => $result,
			)
		);
		return $result;
	}

	private function dispatch( string $event, int $wooId ): string {
		$order = $this->orders->byWoo( $wooId );
		if ( ! $order ) {
			$wc   = wc_get_order( $wooId );
			$meta = $wc ? (int) $wc->get_meta( OrderService::META ) : 0;
			$order = $meta ? $this->orders->find( $meta ) : null;
			if ( ! $order ) {
				return 'ignored';
			}
		}
		$id = (int) $order['id'];
		switch ( $event ) {
			case 'processing':
			case 'completed':
			case 'payment_complete':
				return $this->activate( $id );
			case 'failed':
				if ( 'pending' === $order['status'] ) {
					wvm( OrderService::class )->markFailed( $id, 'Gateway reported failure' );
					return 'failed';
				}
				return 'noop';
			case 'cancelled':
				if ( in_array( $order['status'], array( 'pending', 'failed' ), true ) ) {
					wvm( OrderService::class )->cancel( $id, 'Cancelled in WooCommerce' );
					return 'cancelled';
				}
				return 'noop';
			case 'refunded':
				$wc = wc_get_order( $wooId );
				if ( $wc && 'paid' === $order['status'] && (float) $wc->get_total_refunded() >= (float) $wc->get_total() ) {
					$this->orders->update( $id, array( 'status' => 'refunded' ) );
					foreach ( $this->payments->where( array( 'order_id' => $id ) ) as $pay ) {
						$this->payments->update( (int) $pay['id'], array( 'status' => 'refunded' ) );
					}
					return 'refunded';
				}
				return 'noop';
		}
		return 'ignored';
	}

	/**
	 * Activate a paid order. $force skips amount verification (admin approval); $retry allows re-running
	 * an order parked in needs_review because of an activation error (replay after a fix).
	 */
	public function activate( int $orderId, bool $force = false, bool $retry = false ): string {
		$order = $this->orders->findOrFail( $orderId, 'order' );
		if ( 'paid' === $order['status'] || 'refunded' === $order['status'] ) {
			return 'duplicate';
		}
		if ( 'needs_review' === $order['status'] && ! $force && ! ( $retry && str_starts_with( (string) $order['review_reason'], 'activation_error' ) ) ) {
			return 'needs_review';
		}
		if ( 'cancelled' === $order['status'] && ! $force ) {
			// A late payment on a cancelled order still needs a human.
			wvm( OrderService::class )->markNeedsReview( $orderId, 'paid_after_cancel' );
			return 'needs_review';
		}
		$wc = $order['woo_order_id'] ? wc_get_order( (int) $order['woo_order_id'] ) : null;
		if ( ! $wc ) {
			return 'ignored';
		}
		if ( ! $wc->is_paid() && ! $force ) {
			return 'not_paid';
		}
		if ( ! $force ) {
			$paid_minor = Money::toMinor( (float) $wc->get_total(), $wc->get_currency() );
			if ( strtoupper( $wc->get_currency() ) !== strtoupper( (string) $order['currency'] ) ) {
				wvm( OrderService::class )->markNeedsReview( $orderId, sprintf( 'currency_mismatch: expected %s, got %s', $order['currency'], $wc->get_currency() ) );
				return 'needs_review';
			}
			if ( $paid_minor !== (int) $order['amount_minor'] ) {
				wvm( OrderService::class )->markNeedsReview( $orderId, sprintf( 'amount_mismatch: expected %d, got %d', $order['amount_minor'], $paid_minor ) );
				return 'needs_review';
			}
		}

		try {
			Db::transaction(
				function () use ( $orderId, $wc ): void {
					$locked = $this->orders->lock( $orderId );
					if ( ! $locked || 'paid' === $locked['status'] ) {
						throw new AlreadyPaid();
					}
					$this->recordPayment( $locked, $wc );
					$this->apply( $locked );
					wvm( OrderService::class )->markPaid( $orderId );
				}
			);
		} catch ( AlreadyPaid $e ) {
			return 'duplicate';
		} catch ( \Throwable $e ) {
			wvm( OrderService::class )->markNeedsReview( $orderId, 'activation_error: ' . mb_substr( $e->getMessage(), 0, 200 ) );
			return 'needs_review';
		}

		$paid = wvm( OrderService::class )->get( $orderId );
		wvm( Audit::class )->log( 'order.paid', 'order', $orderId, null, $paid );
		if ( ! $wc->has_status( 'completed' ) ) {
			$wc->update_status( 'completed', 'WitsValley membership activated.' );
		}
		wvm( Notifier::class )->member(
			(int) $order['member_id'],
			'receipt',
			array(
				'amount'         => $paid['amount_display'],
				'plan'           => $paid['plan_name'],
				'receipt_number' => $paid['receipt_number'],
				'receipt_url'    => $paid['receipt_url'],
				'order_id'       => $orderId,
			),
			'receipt',
			'Payment received',
			$paid['amount_display'] . ' — receipt ' . $paid['receipt_number']
		);
		do_action( 'wvm/order/paid', $paid );
		return 'activated';
	}

	/**
	 * @param array<string,mixed> $order
	 */
	private function apply( array $order ): void {
		$service = wvm( SubscriptionService::class );
		$sub     = $order['subscription_id'] ? $this->subs->find( (int) $order['subscription_id'] ) : null;
		switch ( $order['type'] ) {
			case 'new':
				$service->start( (int) $order['member_id'], (int) $order['plan_id'], $order, $sub ? (int) $sub['id'] : null, 'Payment received', $order['organisation_id'] ? (int) $order['organisation_id'] : null );
				return;
			case 'renewal':
				if ( ! $sub ) {
					throw new DomainError( 'Renewal order without subscription.' );
				}
				$service->renew( $sub, $order );
				return;
			case 'reactivation':
				if ( ! $sub ) {
					throw new DomainError( 'Reactivation order without subscription.' );
				}
				if ( 'expired' === $sub['status'] && ! $service->withinRejoinWindow( $sub ) ) {
					$service->start( (int) $order['member_id'], (int) $order['plan_id'], array_merge( $order, array( 'subscription_id' => null ) ), null, 'Rejoined after rejoin window' );
					return;
				}
				$service->restore( $sub, $order );
				return;
			case 'upgrade':
				if ( ! $sub ) {
					throw new DomainError( 'Upgrade order without subscription.' );
				}
				$service->applyPlanChange( $sub, (int) $order['plan_id'], $order, 'Upgrade paid' );
				return;
			case 'downgrade':
				if ( $sub ) {
					$service->changePlan( $sub, (int) $order['plan_id'], 'at_renewal' );
				}
				return;
			case 'enterprise':
				do_action( 'wvm/enterprise/invoice_paid', $order );
				return;
			case 'manual_adjust':
				return;
		}
		throw new DomainError( 'Unknown order type ' . $order['type'] );
	}

	/** @param array<string,mixed> $order */
	private function recordPayment( array $order, \WC_Order $wc ): void {
		$gateway = $wc->get_payment_method() ?: 'unknown';
		$ref     = $this->providerRef( $wc, $gateway );
		$exists  = $this->payments->findBy( 'provider_ref', $ref );
		if ( $exists ) {
			if ( (int) $exists['order_id'] !== (int) $order['id'] ) {
				throw new DomainError( 'duplicate provider reference ' . $ref );
			}
			$this->payments->update( (int) $exists['id'], array( 'status' => 'confirmed', 'confirmed_at' => Clock::mysql() ) );
			return;
		}
		$meta = array();
		foreach ( $wc->get_meta_data() as $m ) {
			$data = $m->get_data();
			if ( is_scalar( $data['value'] ) && strlen( (string) $data['value'] ) < 500 ) {
				$meta[ (string) $data['key'] ] = $data['value'];
			}
		}
		$this->payments->insert(
			array(
				'order_id'     => (int) $order['id'],
				'gateway'      => $gateway,
				'provider_ref' => $ref,
				'amount_minor' => Money::toMinor( (float) $wc->get_total(), $wc->get_currency() ),
				'currency'     => $wc->get_currency(),
				'status'       => 'confirmed',
				'raw_payload'  => (string) wp_json_encode(
					array(
						'transaction_id' => $wc->get_transaction_id(),
						'method_title'   => $wc->get_payment_method_title(),
						'date_paid'      => $wc->get_date_paid() ? $wc->get_date_paid()->format( 'c' ) : null,
						'meta'           => $meta,
					)
				),
				'confirmed_at' => Clock::mysql(),
			)
		);
	}

	/** Transaction id from Woo order meta via the per-gateway adapter map (docs/ADDING_A_GATEWAY.md). */
	public function providerRef( \WC_Order $wc, string $gateway ): string {
		$map  = (array) $this->settings->get( 'payments', 'transaction_meta_keys', array() );
		$keys = (array) apply_filters( 'wvm_transaction_meta_keys', $map[ $gateway ] ?? array(), $gateway, $wc );
		foreach ( $keys as $key ) {
			$value = '_transaction_id' === $key ? $wc->get_transaction_id() : $wc->get_meta( (string) $key );
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				return $gateway . ':' . mb_substr( (string) $value, 0, 150 );
			}
		}
		if ( $wc->get_transaction_id() ) {
			return $gateway . ':' . mb_substr( $wc->get_transaction_id(), 0, 150 );
		}
		return $gateway . ':woo-' . $wc->get_id();
	}

	/** Re-run the listener for an order from its latest inbox entry (after a fix). */
	public function replay( int $orderId ): string {
		$order = $this->orders->findOrFail( $orderId, 'order' );
		if ( ! $order['woo_order_id'] ) {
			throw DomainError::conflict( 'This order has no WooCommerce order to replay.' );
		}
		$result = Audit::asSystem( fn() => $this->activate( $orderId, false, true ) );
		$this->inbox->insert(
			array(
				'woo_order_id' => (int) $order['woo_order_id'],
				'event'        => 'replay',
				'payload'      => array( 'by' => get_current_user_id() ),
				'processed_at' => Clock::mysql(),
				'result'       => $result,
			)
		);
		wvm( Audit::class )->log( 'order.replayed', 'order', $orderId, null, array( 'result' => $result ) );
		return $result;
	}
}
