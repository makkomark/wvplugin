<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Access\LevelResolver;
use WitsValley\Members\Domain\Audit\AuditService;
use WitsValley\Members\Domain\Kit\KitSync;
use WitsValley\Members\Domain\Member\MemberService;
use WitsValley\Members\Domain\Notification\Mailer;
use WitsValley\Members\Domain\Order\OrderRepository;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Payment\PaymentMethodService;
use WitsValley\Members\Domain\Subscription\SubscriptionRepository;
use WitsValley\Members\Domain\Subscription\SubscriptionService;
use WitsValley\Members\Domain\Enterprise\OrganisationService;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Money;
use WitsValley\Members\Support\Pagination;

final class MembersController extends Controller {

	public function __construct(
		private MemberService $members,
		private SubscriptionService $subs,
		private OrderService $orders,
		private Mailer $mailer
	) {}

	public function routes(): void {
		$view = 'wvm_view_members';
		$edit = 'wvm_edit_members';
		$this->route( 'GET', '/members', fn( $r ) => $this->paged( $this->members->list( $this->query( $r ), $this->pagination( $r ) ) ), $view );
		$this->route( 'GET', '/members/export\.csv', fn( $r ) => $this->download( $this->members->exportCsv( $this->query( $r ) ), 'witsvalley-members-' . gmdate( 'Y-m-d' ) . '.csv', 'text/csv' ), $view );
		$this->route( 'POST', '/members', fn( $r ) => $this->ok( $this->create( $this->body( $r ) ), null, 201 ), $edit );
		$this->route( 'POST', '/members/bulk', fn( $r ) => $this->bulk( $this->body( $r ) ), $edit );
		$this->route( 'GET', '/members/(?P<id>\d+)', fn( $r ) => $this->detail( $this->id( $r ) ), $view );
		$this->route( 'PATCH', '/members/(?P<id>\d+)', fn( $r ) => $this->members->update( $this->id( $r ), $this->body( $r ), (string) ( $this->body( $r )['reason'] ?? '' ) ?: null ), $edit );
		$this->route( 'GET', '/members/(?P<id>\d+)/subscriptions', fn( $r ) => $this->subscriptions( $this->id( $r ) ), $view );
		$this->route( 'GET', '/members/(?P<id>\d+)/orders', fn( $r ) => $this->paged( $this->orders->list( array( 'member' => $this->id( $r ) ), $this->pagination( $r, 50 ) ) ), array( 'any' => array( 'wvm_view_payments', 'wvm_view_members' ) ) );
		$this->route( 'GET', '/members/(?P<id>\d+)/notifications', fn( $r ) => $this->paged( $this->mailer->list( array( 'member' => $this->id( $r ) ), $this->pagination( $r, 50 ) ) ), $view );
		$this->route( 'GET', '/members/(?P<id>\d+)/audit', fn( $r ) => $this->paged( wvm( AuditService::class )->forMember( $this->id( $r ), $this->pagination( $r, 50 ) ) ), $view );
		$this->route( 'GET', '/members/(?P<id>\d+)/notes', fn( $r ) => $this->members->notes( $this->id( $r ) ), $view );
		$this->route( 'POST', '/members/(?P<id>\d+)/notes', fn( $r ) => $this->ok( $this->members->addNote( $this->id( $r ), (string) ( $this->body( $r )['body'] ?? '' ) ), null, 201 ), $edit );
		$this->route(
			'DELETE',
			'/members/(?P<id>\d+)/notes/(?P<note>\d+)',
			function ( $r ) {
				$this->members->deleteNote( $this->id( $r ), $this->id( $r, 'note' ) );
				return array( 'deleted' => true );
			},
			$edit
		);
		$this->route( 'POST', '/members/(?P<id>\d+)/resend-welcome', fn( $r ) => $this->resendWelcome( $this->id( $r ) ), $edit );
		$this->route( 'POST', '/members/(?P<id>\d+)/send-pay-link', fn( $r ) => $this->sendPayLink( $this->id( $r ), $this->body( $r ) ), $edit );
		$this->route( 'POST', '/members/(?P<id>\d+)/kit-resync', fn( $r ) => wvm( KitSync::class )->syncMember( $this->id( $r ) ), array( 'any' => array( 'wvm_edit_members', 'wvm_manage_settings' ) ) );
		$this->route( 'POST', '/members/(?P<id>\d+)/impersonate', fn( $r ) => array( 'url' => $this->members->impersonate( $this->id( $r ) ) ), 'wvm_impersonate' );
		$this->route( 'POST', '/members/(?P<id>\d+)/anonymise', fn( $r ) => $this->anonymise( $r ), 'wvm_manage_settings' );
		$this->route(
			'POST',
			'/members/(?P<id>\d+)/data-export',
			fn( $r ) => array(
				'request_id' => $this->members->requestDataExport( $this->id( $r ) ),
				'url'        => admin_url( 'export-personal-data.php' ),
			),
			$edit
		);
	}

	/** @return array<string,mixed> */
	private function detail( int $id ): array {
		$member  = $this->members->get( $id );
		$current = $this->subs->currentFor( $id );
		global $wpdb;
		$o       = Db::table( 'orders' );
		$paid    = $wpdb->get_results( $wpdb->prepare( "SELECT currency, SUM(amount_minor) total, COUNT(*) n FROM {$o} WHERE member_id = %d AND status = 'paid' GROUP BY currency", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$ent     = wvm( LevelResolver::class )->entitlements( (int) $member['user_id'] );
		$pending = wvm( OrderRepository::class )->first(
			array(
				'member_id' => $id,
				'status'    => 'pending',
			)
		);
		return array(
			'member'          => $member,
			'subscription'    => $current,
			'entitlements'    => $ent,
			'organisation'    => wvm( OrganisationService::class )->forMember( $id ),
			'pending_order'   => $pending ? $this->orders->present( $pending ) : null,
			'payment_methods' => wvm( PaymentMethodService::class )->forMember( $id ),
			'notes'           => $this->members->notes( $id ),
			'stats'           => array(
				'lifetime_value' => array_map(
					static fn( $r ) => array(
						'currency'    => $r['currency'],
						'total_minor' => (int) $r['total'],
						'display'     => Money::format( (int) $r['total'], (string) $r['currency'] ),
						'orders'      => (int) $r['n'],
					),
					$paid ?: array()
				),
				'member_since'   => $member['created_at'],
				'subscriptions'  => count( $this->subs->forMember( $id ) ),
			),
			'kit'             => array(
				'subscriber_id' => $member['kit_subscriber_id'],
				'last_synced'   => $member['kit_last_synced_at'],
				'error'         => $member['kit_sync_error'],
				'expected'      => wvm( KitSync::class )->stateFor( $id ),
			),
			'wp_user_url'     => admin_url( 'user-edit.php?user_id=' . (int) $member['user_id'] ),
		);
	}

	/** @return list<array<string,mixed>> */
	private function subscriptions( int $id ): array {
		return array_map(
			fn( $s ) => $s + array( 'events' => $this->subs->eventsFor( (int) $s['id'] ) ),
			$this->subs->forMember( $id )
		);
	}

	/**
	 * Admin-created member, optional initial plan without payment.
	 *
	 * @param array<string,mixed> $b
	 * @return array<string,mixed>
	 */
	private function create( array $b ): array {
		$member = $this->members->createFromSignup( $b, false );
		if ( ! empty( $b['plan_id'] ) ) {
			$this->subs->adminGrant( (int) $member['id'], (int) $b['plan_id'], (string) ( $b['reason'] ?? 'Created by admin' ) ?: 'Created by admin', true, ! empty( $b['days'] ) ? (int) $b['days'] : null );
		} else {
			$this->subs->startFree( (int) $member['id'] );
		}
		if ( ! empty( $b['send_welcome'] ) ) {
			wp_new_user_notification( (int) $member['user_id'], null, 'user' );
		}
		return $this->detail( (int) $member['id'] );
	}

	/** @param array<string,mixed> $b */
	private function bulk( array $b ): array {
		$ids    = array_values( array_filter( array_map( 'intval', (array) ( $b['ids'] ?? array() ) ) ) );
		$action = (string) ( $b['action'] ?? '' );
		$value  = (string) ( $b['value'] ?? '' );
		if ( ! $ids ) {
			throw DomainError::validation( array( 'ids' => 'Select at least one member.' ) );
		}
		if ( 'kit_tag' === $action ) {
			return array( 'queued' => $this->members->bulkTag( $ids, $value ) );
		}
		if ( 'email_template' === $action ) {
			$count = 0;
			foreach ( $ids as $id ) {
				if ( $this->mailer->send( $value, $id, array() ) ) {
					++$count;
				}
			}
			wvm( \WitsValley\Members\Domain\Audit\Audit::class )->log(
				'member.bulk_email',
				'member',
				null,
				null,
				array(
					'ids'      => $ids,
					'template' => $value,
					'queued'   => $count,
				)
			);
			return array( 'queued' => $count );
		}
		throw DomainError::validation( array( 'action' => 'Unknown bulk action.' ) );
	}

	/** @return array<string,mixed> */
	private function resendWelcome( int $id ): array {
		$sub = $this->subs->currentFor( $id );
		$this->mailer->send( 'welcome', $id, $sub ? $this->subs->payload( $sub ) : array() );
		wvm( \WitsValley\Members\Domain\Audit\Audit::class )->log( 'member.welcome_resent', 'member', $id );
		return array( 'sent' => true );
	}

	/**
	 * @param array<string,mixed> $b plan_id?, currency?
	 * @return array<string,mixed>
	 */
	private function sendPayLink( int $id, array $b ): array {
		$sub = wvm( SubscriptionRepository::class )->current( $id );
		if ( $sub && in_array( $sub['status'], array( 'active', 'past_due', 'suspended', 'expired' ), true ) && empty( $b['plan_id'] ) && ! $this->isFree( (int) $sub['plan_id'] ) ) {
			$order = $this->orders->ensureRenewalOrder( $sub );
		} else {
			$plan_id = (int) ( $b['plan_id'] ?? 0 );
			if ( ! $plan_id ) {
				throw DomainError::validation( array( 'plan_id' => 'Choose a plan for the payment link.' ) );
			}
			$order = $this->orders->createForPlan( $id, $plan_id, 'new', strtoupper( (string) ( $b['currency'] ?? 'UGX' ) ) );
		}
		$this->mailer->send(
			'pay_link',
			$id,
			array(
				'pay_link' => $order['pay_link'],
				'amount'   => $order['amount_display'],
				'plan'     => $order['plan_name'],
			)
		);
		wvm( \WitsValley\Members\Domain\Audit\Audit::class )->log( 'member.pay_link_sent', 'member', $id, null, array( 'order_id' => $order['id'] ) );
		return $order;
	}

	private function isFree( int $planId ): bool {
		return (bool) wvm( \WitsValley\Members\Domain\Plan\PlanService::class )->get( $planId )['is_free'];
	}

	/** @return array<string,mixed> */
	private function anonymise( \WP_REST_Request $r ): array {
		if ( ! current_user_can( 'wvm_manage_settings' ) ) {
			throw DomainError::forbidden();
		}
		return $this->members->anonymise( $this->id( $r ), $this->reason( $r ) );
	}
}
