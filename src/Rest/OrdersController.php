<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Order\OrderListener;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Subscription\SubscriptionRepository;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Pagination;

final class OrdersController extends Controller {

	public function __construct( private OrderService $orders ) {}

	public function routes(): void {
		$view   = 'wvm_view_payments';
		$manage = 'wvm_manage_payments';
		$this->route( 'GET', '/orders', fn( $r ) => $this->paged( $this->orders->list( $this->query( $r ), $this->pagination( $r ) ) ), $view );
		$this->route( 'GET', '/orders/review-queue', fn( $r ) => $this->paged( $this->orders->list( array( 'status' => 'needs_review' ), $this->pagination( $r, 50 ) ) ), $view );
		$this->route( 'GET', '/orders/export\.csv', fn( $r ) => $this->download( $this->csv( $this->query( $r ) ), 'witsvalley-payments-' . gmdate( 'Y-m-d' ) . '.csv', 'text/csv' ), $view );
		$this->route( 'GET', '/orders/(?P<id>\d+)', fn( $r ) => $this->orders->detail( $this->id( $r ) ), $view );
		$this->route( 'POST', '/orders', fn( $r ) => $this->ok( $this->create( $this->body( $r ) ), null, 201 ), array( 'any' => array( $manage, 'wvm_edit_members' ) ) );
		$this->route(
			'POST',
			'/orders/manual',
			function ( $r ) {
				$b = $this->body( $r );
				return $this->ok( $this->orders->recordManual( (int) ( $b['member_id'] ?? 0 ), (int) ( $b['plan_id'] ?? 0 ), (int) ( $b['amount_minor'] ?? 0 ), (string) ( $b['currency'] ?? 'UGX' ), sanitize_text_field( (string) ( $b['method'] ?? 'Bank transfer' ) ), sanitize_text_field( (string) ( $b['reference'] ?? '' ) ), ! empty( $b['paid_at'] ) ? (string) $b['paid_at'] : null ), null, 201 );
			},
			$manage
		);
		$this->route( 'POST', '/orders/(?P<id>\d+)/approve', fn( $r ) => $this->orders->approveReview( $this->id( $r ), $this->reason( $r ) ), $manage );
		$this->route( 'POST', '/orders/(?P<id>\d+)/reject', fn( $r ) => $this->orders->rejectReview( $this->id( $r ), $this->reason( $r ) ), $manage );
		$this->route(
			'POST',
			'/orders/(?P<id>\d+)/refund',
			function ( $r ) {
				$b = $this->body( $r );
				return $this->orders->refund( $this->id( $r ), (int) ( $b['amount_minor'] ?? 0 ), $this->reason( $r ), ! empty( $b['end_membership'] ) );
			},
			$manage
		);
		$this->route( 'POST', '/orders/(?P<id>\d+)/cancel', fn( $r ) => $this->orders->cancel( $this->id( $r ), $this->reason( $r ) ), $manage );
		$this->route(
			'POST',
			'/orders/(?P<id>\d+)/replay',
			function ( $r ) {
				$result = wvm( OrderListener::class )->replay( $this->id( $r ) );
				return array(
					'result' => $result,
					'order'  => $this->orders->detail( $this->id( $r ) ),
				);
			},
			$manage
		);
	}

	/**
	 * Admin-created pending order + pay link.
	 *
	 * @param array<string,mixed> $b member_id, plan_id, type, currency
	 * @return array<string,mixed>
	 */
	private function create( array $b ): array {
		$member = (int) ( $b['member_id'] ?? 0 );
		$plan   = (int) ( $b['plan_id'] ?? 0 );
		$type   = (string) ( $b['type'] ?? 'new' );
		if ( ! in_array( $type, array( 'new', 'renewal', 'reactivation' ), true ) ) {
			throw DomainError::validation( array( 'type' => 'Type must be new, renewal or reactivation.' ) );
		}
		if ( 'new' === $type ) {
			$order = $this->orders->createForPlan( $member, $plan, 'new', strtoupper( (string) ( $b['currency'] ?? 'UGX' ) ) );
		} else {
			$sub = wvm( SubscriptionRepository::class )->current( $member );
			if ( ! $sub ) {
				throw DomainError::validation( array( 'type' => 'This member has no subscription to renew.' ) );
			}
			$order = $this->orders->ensureRenewalOrder( $sub );
		}
		if ( ! empty( $b['send_email'] ) ) {
			wvm( \WitsValley\Members\Domain\Notification\Mailer::class )->send(
				'pay_link',
				$member,
				array(
					'pay_link' => $order['pay_link'],
					'amount'   => $order['amount_display'],
					'plan'     => $order['plan_name'],
				)
			);
		}
		return $order;
	}

	/** @param array<string,mixed> $f */
	private function csv( array $f ): string {
		$fh = fopen( 'php://temp', 'w+' );
		if ( ! $fh ) {
			return '';
		}
		fputcsv( $fh, array( 'Order', 'Woo order', 'Date (UTC)', 'Member ID', 'Member', 'Email', 'Plan', 'Type', 'Status', 'Currency', 'Amount', 'Gateway', 'Provider ref', 'Receipt' ) );
		$page = 1;
		do {
			$res = $this->orders->list( $f, new Pagination( $page, 200 ) );
			foreach ( $res['items'] as $o ) {
				fputcsv( $fh, array( $o['id'], $o['woo_order_id'], $o['created_at'], $o['member']['public_id'] ?? '', $o['member']['name'] ?? '', $o['member']['email'] ?? '', $o['plan_name'], $o['type'], $o['status'], $o['currency'], $o['amount_display'], $o['gateway'], $o['provider_ref'], $o['receipt_number'] ) );
			}
			++$page;
		} while ( $page <= $res['meta']['pages'] && $page < 200 );
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh );
		wvm( \WitsValley\Members\Domain\Audit\Audit::class )->log( 'order.exported', 'order', null, null, array( 'filters' => $f ) );
		return $csv;
	}
}
