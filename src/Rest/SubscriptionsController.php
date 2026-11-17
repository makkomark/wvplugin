<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Notification\Mailer;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Subscription\SubscriptionService;
use WitsValley\Members\Support\DomainError;

final class SubscriptionsController extends Controller {

	public function __construct( private SubscriptionService $subs, private OrderService $orders ) {}

	public function routes(): void {
		$cap = 'wvm_edit_members';
		$this->route( 'GET', '/subscriptions', fn( $r ) => $this->paged( $this->subs->list( $this->query( $r ), $this->pagination( $r ) ) ), array( 'any' => array( 'wvm_view_members', 'wvm_edit_members' ) ) );
		$this->route( 'GET', '/subscriptions/(?P<id>\d+)', fn( $r ) => $this->subs->get( $this->id( $r ) ) + array( 'events' => $this->subs->eventsFor( $this->id( $r ) ) ), array( 'any' => array( 'wvm_view_members', 'wvm_edit_members' ) ) );
		$this->route(
			'POST',
			'/subscriptions',
			function ( $r ) {
				$b = $this->body( $r );
				return $this->ok( $this->subs->adminGrant( (int) ( $b['member_id'] ?? 0 ), (int) ( $b['plan_id'] ?? 0 ), $this->reason( $r ), ! isset( $b['start_now'] ) || (bool) $b['start_now'], ! empty( $b['days'] ) ? (int) $b['days'] : null ), null, 201 );
			},
			$cap
		);
		$this->route(
			'POST',
			'/subscriptions/(?P<id>\d+)/change-plan',
			function ( $r ) {
				$b      = $this->body( $r );
				$mode   = 'at_renewal' === ( $b['mode'] ?? '' ) ? 'at_renewal' : 'immediate';
				$charge = ! empty( $b['charge'] );
				return $this->subs->changePlan( $this->subs->raw( $this->id( $r ) ), (int) ( $b['plan_id'] ?? 0 ), $mode, $charge, $this->reason( $r ) );
			},
			$cap
		);
		$this->route( 'POST', '/subscriptions/(?P<id>\d+)/extend', fn( $r ) => $this->subs->extend( $this->subs->raw( $this->id( $r ) ), (int) ( $this->body( $r )['days'] ?? 0 ), $this->reason( $r ) ), $cap );
		$this->route( 'POST', '/subscriptions/(?P<id>\d+)/suspend', fn( $r ) => $this->subs->adminSuspend( $this->subs->raw( $this->id( $r ) ), $this->reason( $r ) ), $cap );
		$this->route( 'POST', '/subscriptions/(?P<id>\d+)/reactivate', fn( $r ) => $this->subs->adminReactivate( $this->subs->raw( $this->id( $r ) ), $this->reason( $r ) ), $cap );
		$this->route(
			'POST',
			'/subscriptions/(?P<id>\d+)/cancel',
			function ( $r ) {
				$reason = $this->reason( $r );
				$after  = $this->subs->cancel( $this->subs->raw( $this->id( $r ) ), $reason, ! empty( $this->body( $r )['immediate'] ) );
				wvm( \WitsValley\Members\Domain\Audit\Audit::class )->log( 'subscription.admin_cancelled', 'subscription', $this->id( $r ), null, $after, $reason );
				return $after;
			},
			$cap
		);
		$this->route( 'POST', '/subscriptions/(?P<id>\d+)/resume', fn( $r ) => $this->subs->resume( $this->subs->raw( $this->id( $r ) ) ), $cap );
		$this->route(
			'POST',
			'/subscriptions/(?P<id>\d+)/send-pay-link',
			function ( $r ) {
				$sub = $this->subs->raw( $this->id( $r ) );
				if ( in_array( $sub['status'], array( 'cancelled', 'pending' ), true ) ) {
					throw DomainError::conflict( 'Pay links are for live or lapsed memberships.' );
				}
				$order = $this->orders->ensureRenewalOrder( $sub );
				wvm( Mailer::class )->send(
					'pay_link',
					(int) $sub['member_id'],
					array(
						'pay_link' => $order['pay_link'],
						'amount'   => $order['amount_display'],
						'plan'     => $order['plan_name'],
					)
				);
				wvm( \WitsValley\Members\Domain\Audit\Audit::class )->log( 'subscription.pay_link_sent', 'subscription', (int) $sub['id'], null, array( 'order_id' => $order['id'] ) );
				return $order;
			},
			$cap
		);
	}
}
