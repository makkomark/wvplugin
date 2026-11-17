<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Access\LevelResolver;
use WitsValley\Members\Domain\Enterprise\OrganisationService;
use WitsValley\Members\Domain\Member\MemberService;
use WitsValley\Members\Domain\Notification\InAppNotifier;
use WitsValley\Members\Domain\Notification\Mailer;
use WitsValley\Members\Domain\Notification\Receipt;
use WitsValley\Members\Domain\Notification\Urls;
use WitsValley\Members\Domain\Order\OrderRepository;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Payment\PaymentMethodService;
use WitsValley\Members\Domain\Plan\PlanService;
use WitsValley\Members\Domain\Subscription\StateMachine;
use WitsValley\Members\Domain\Subscription\SubscriptionRepository;
use WitsValley\Members\Domain\Subscription\SubscriptionService;
use WitsValley\Members\Domain\Workshop\ConsultationService;
use WitsValley\Members\Domain\Workshop\WorkshopService;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Pagination;
use WitsValley\Members\Support\Settings;

/**
 * Member self-service endpoints. Every route operates on the logged-in member only.
 */
final class MeController extends Controller {

	public function __construct(
		private MemberService $members,
		private SubscriptionService $subs,
		private SubscriptionRepository $subRepo,
		private OrderService $orders,
		private PlanService $plans,
		private Settings $settings
	) {}

	public function routes(): void {
		$m = 'member';
		$this->route( 'GET', '/me', fn() => $this->overview(), $m );
		$this->route( 'PATCH', '/me', fn( $r ) => $this->members->update( (int) $this->me()['id'], array_intersect_key( $this->body( $r ), array_flip( array( 'name', 'phone', 'country', 'timezone', 'notification_prefs', 'marketing_consent' ) ) ) ), $m );
		$this->route(
			'POST',
			'/me/email-change',
			function ( $r ) {
				$this->members->requestEmailChange( (int) $this->me()['id'], (string) ( $this->body( $r )['email'] ?? '' ) );
				return array( 'sent' => true );
			},
			$m
		);
		$this->route( 'POST', '/me/email-change/confirm', fn( $r ) => $this->members->confirmEmailChange( (string) ( $this->body( $r )['token'] ?? '' ), (int) $this->me()['id'] ), $m );
		$this->route( 'GET', '/me/sessions', fn() => $this->members->sessions( get_current_user_id() ), $m );
		$this->route(
			'DELETE',
			'/me/sessions/others',
			function () {
				$this->members->destroyOtherSessions( get_current_user_id() );
				return $this->members->sessions( get_current_user_id() );
			},
			$m
		);
		$this->route(
			'POST',
			'/me/data-export',
			function () {
				$this->rateLimit( 'export', 3, DAY_IN_SECONDS );
				$id = $this->members->requestDataExport( (int) $this->me()['id'] );
				return array(
					'requested'  => true,
					'request_id' => $id,
				);
			},
			$m
		);
		$this->route(
			'POST',
			'/me/delete-request',
			function () {
				$this->rateLimit( 'delete', 3, DAY_IN_SECONDS );
				$id = $this->members->requestDeletion( (int) $this->me()['id'] );
				return array(
					'requested'  => true,
					'request_id' => $id,
				);
			},
			$m
		);

		// Subscription.
		$this->route( 'GET', '/me/subscription', fn() => $this->subs->currentFor( (int) $this->me()['id'] ), $m );
		$this->route( 'GET', '/me/subscription/upgrade-preview', fn( $r ) => $this->preview( (int) $r->get_param( 'plan_id' ), (string) $r->get_param( 'currency' ) ), $m );
		$this->route( 'POST', '/me/subscription/upgrade', fn( $r ) => $this->upgrade( (int) ( $this->body( $r )['plan_id'] ?? 0 ), (string) ( $this->body( $r )['currency'] ?? '' ) ), $m );
		$this->route( 'POST', '/me/subscription/downgrade', fn( $r ) => $this->downgrade( (int) ( $this->body( $r )['plan_id'] ?? 0 ) ), $m );
		$this->route( 'POST', '/me/subscription/cancel', fn( $r ) => $this->subs->cancel( $this->live(), (string) ( $this->body( $r )['reason'] ?? '' ) ), $m );
		$this->route( 'POST', '/me/subscription/resume', fn() => $this->subs->resume( $this->live() ), $m );
		$this->route( 'POST', '/me/subscription/pay-now', fn() => $this->payNow(), $m );
		$this->route( 'POST', '/me/subscription/join-free', fn() => $this->subs->startFree( (int) $this->me()['id'] ), $m );

		// Orders & payment methods.
		$this->route( 'GET', '/me/orders', fn( $r ) => $this->paged( $this->myOrders( $this->pagination( $r, 20 ) ) ), $m );
		$this->route( 'GET', '/me/orders/(?P<id>\d+)/receipt\.pdf', fn( $r ) => $this->receipt( $this->id( $r ) ), $m );
		$this->route( 'GET', '/me/payment-methods', fn() => wvm( PaymentMethodService::class )->forMember( (int) $this->me()['id'] ), $m );
		$this->route(
			'DELETE',
			'/me/payment-methods/(?P<id>\d+)',
			function ( $r ) {
				wvm( PaymentMethodService::class )->delete( (int) $this->me()['id'], $this->id( $r ) );
				return wvm( PaymentMethodService::class )->forMember( (int) $this->me()['id'] );
			},
			$m
		);
		$this->route(
			'POST',
			'/me/payment-methods/default/(?P<id>\d+)',
			function ( $r ) {
				wvm( PaymentMethodService::class )->setDefault( (int) $this->me()['id'], $this->id( $r ) );
				return wvm( PaymentMethodService::class )->forMember( (int) $this->me()['id'] );
			},
			$m
		);

		// Notifications.
		$this->route( 'GET', '/me/notifications', fn( $r ) => $this->ok( wvm( InAppNotifier::class )->list( (int) $this->me()['id'], ! empty( $r['unread'] ) ), array( 'unread' => wvm( InAppNotifier::class )->unreadCount( (int) $this->me()['id'] ) ) ), $m );
		$this->route(
			'POST',
			'/me/notifications/(?P<id>\d+)/read',
			function ( $r ) {
				wvm( InAppNotifier::class )->markRead( (int) $this->me()['id'], $this->id( $r ) );
				return array( 'unread' => wvm( InAppNotifier::class )->unreadCount( (int) $this->me()['id'] ) );
			},
			$m
		);
		$this->route(
			'POST',
			'/me/notifications/read-all',
			function () {
				wvm( InAppNotifier::class )->markAllRead( (int) $this->me()['id'] );
				return array( 'unread' => 0 );
			},
			$m
		);

		// Workshops & consultations.
		$this->route( 'GET', '/me/workshops', fn( $r ) => wvm( WorkshopService::class )->forMember( (int) $this->me()['id'], 'past' === $r['when'] ? 'past' : 'upcoming' ), $m );
		$this->route( 'POST', '/me/workshops/(?P<id>\d+)/register', fn( $r ) => wvm( WorkshopService::class )->register( $this->id( $r ), (int) $this->me()['id'] ), $m );
		$this->route( 'DELETE', '/me/workshops/(?P<id>\d+)/register', fn( $r ) => wvm( WorkshopService::class )->unregister( $this->id( $r ), (int) $this->me()['id'] ), $m );
		$this->route( 'GET', '/me/workshops/(?P<id>\d+)/join', fn( $r ) => wvm( WorkshopService::class )->joinUrl( $this->id( $r ), (int) $this->me()['id'] ), $m );
		$this->route( 'GET', '/me/workshops/(?P<id>\d+)/calendar\.ics', fn( $r ) => $this->download( wvm( WorkshopService::class )->ics( $this->id( $r ) ), 'witsvalley-workshop-' . $this->id( $r ) . '.ics', 'text/calendar' ), $m );
		$this->route( 'GET', '/me/consultations', fn() => wvm( ConsultationService::class )->forMember( (int) $this->me()['id'] ), $m );
		$this->route( 'POST', '/me/consultations', fn( $r ) => $this->ok( wvm( ConsultationService::class )->request( (int) $this->me()['id'], $this->body( $r ) ), null, 201 ), $m );

		// Organisation (seat holders + org admins).
		$this->route( 'GET', '/me/organisation', fn() => wvm( OrganisationService::class )->forMember( (int) $this->me()['id'] ), $m );
		$this->route( 'POST', '/me/organisation/accept', fn( $r ) => wvm( OrganisationService::class )->accept( (string) ( $this->body( $r )['token'] ?? '' ), get_current_user_id() ), $m );
		$this->route( 'GET', '/me/organisation/seats', fn() => wvm( OrganisationService::class )->seatList( $this->orgAdmin() ), $m );
		$this->route(
			'POST',
			'/me/organisation/invites',
			function ( $r ) {
				$org    = $this->orgAdmin();
				$b      = $this->body( $r );
				$emails = isset( $b['csv'] ) ? self::emailsFromCsv( (string) $b['csv'] ) : (array) ( $b['emails'] ?? array() );
				return wvm( OrganisationService::class )->invite( $org, array_map( 'strval', $emails ), 'member' );
			},
			$m
		);
		$this->route(
			'DELETE',
			'/me/organisation/invites/(?P<id>\d+)',
			function ( $r ) {
				$org = $this->orgAdmin();
				wvm( OrganisationService::class )->withdrawInvite( $org, $this->id( $r ) );
				return wvm( OrganisationService::class )->seatList( $org );
			},
			$m
		);
		$this->route(
			'POST',
			'/me/organisation/invites/(?P<id>\d+)/resend',
			function ( $r ) {
				$org = $this->orgAdmin();
				wvm( OrganisationService::class )->resendInvite( $org, $this->id( $r ) );
				return wvm( OrganisationService::class )->seatList( $org );
			},
			$m
		);
		$this->route(
			'DELETE',
			'/me/organisation/seats/(?P<id>\d+)',
			function ( $r ) {
				$org = $this->orgAdmin();
				if ( $this->id( $r ) === (int) $this->me()['id'] ) {
					throw DomainError::conflict( 'You cannot remove your own seat.' );
				}
				wvm( OrganisationService::class )->revoke( $org, $this->id( $r ), 'Removed by organisation admin' );
				return wvm( OrganisationService::class )->seatList( $org );
			},
			$m
		);
	}

	/** @return array<string,mixed> Everything the dashboard needs in one call. */
	private function overview(): array {
		$member   = $this->me();
		$id       = (int) $member['id'];
		$current  = $this->subs->currentFor( $id );
		$ent      = wvm( LevelResolver::class )->entitlements( get_current_user_id() );
		$pending  = wvm( OrderRepository::class )->first(
			array(
				'member_id' => $id,
				'status'    => array( 'pending', 'failed' ),
			)
		);
		$org      = wvm( OrganisationService::class )->forMember( $id );
		$currency = $current && ! $current['plan']['is_free'] ? (string) $current['currency'] : $this->currencyFor( (string) $member['country'] );
		$state    = $this->state( $current );
		$org_sub  = null;
		foreach ( $this->subRepo->live( $id ) as $live ) {
			if ( $live['organisation_id'] ) {
				$org_sub = $this->subs->present( $live );
				break;
			}
		}
		return array(
			'member'           => $member,
			'subscription'     => $current,
			'org_subscription' => $org_sub,
			'state'            => $state,
			'entitlements'     => $ent,
			'level_label'      => LevelResolver::label( (int) $ent['level'] ),
			'pending_order'    => $pending ? $this->orders->present( $pending ) : null,
			'organisation'     => $org ? array(
				'id'              => $org['id'],
				'name'            => $org['name'],
				'role'            => $org['role'],
				'programme_title' => $org['programme_title'],
				'term_end'        => $org['term_end'],
			) : null,
			'next_workshop'    => wvm( WorkshopService::class )->nextFor( $id ),
			'unread'           => wvm( InAppNotifier::class )->unreadCount( $id ),
			'plans'            => $this->plans->getPublic( $currency ),
			'currency'         => $currency,
			'payment_methods'  => wvm( PaymentMethodService::class )->forMember( $id ),
			'impersonating'    => (bool) wvm( \WitsValley\Members\Frontend\Frontend::class )->impersonator(),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
		);
	}

	/**
	 * Dashboard state that drives the banner and primary action.
	 *
	 * @param array<string,mixed>|null $sub
	 */
	private function state( ?array $sub ): string {
		if ( ! $sub ) {
			return 'none';
		}
		if ( $sub['plan']['is_free'] ) {
			return 'free';
		}
		if ( 'active' === $sub['status'] && $sub['cancel_at_period_end'] ) {
			return 'cancelling';
		}
		if ( 'expired' === $sub['status'] && ! $sub['can_rejoin'] ) {
			return 'ended';
		}
		return (string) $sub['status'];
	}

	private function currencyFor( string $country ): string {
		$map     = (array) $this->settings->get( 'payments', 'default_currency_by_country', array() );
		$enabled = (array) $this->settings->get( 'payments', 'currencies_enabled', array( 'UGX' ) );
		$cur     = (string) ( $map[ strtoupper( $country ) ] ?? $map['default'] ?? ( $enabled[0] ?? 'UGX' ) );
		return in_array( $cur, $enabled, true ) ? $cur : (string) ( $enabled[0] ?? 'UGX' );
	}

	/** @return array<string,mixed> Live personal subscription (active/past_due). */
	private function live(): array {
		$sub = $this->subRepo->current( (int) $this->me()['id'] );
		if ( ! $sub || ! StateMachine::grantsAccess( (string) $sub['status'] ) ) {
			throw DomainError::conflict( 'You do not have an active membership.' );
		}
		return $sub;
	}

	/** @return array<string,mixed> */
	private function preview( int $planId, string $currency ): array {
		$member = $this->me();
		$sub    = $this->subRepo->current( (int) $member['id'] );
		$plan   = $this->plans->get( $planId );
		$this->assertJoinable( $plan );
		if ( ! $sub || ! StateMachine::grantsAccess( (string) $sub['status'] ) ) {
			$cur   = strtoupper( $currency ?: $this->currencyFor( (string) $member['country'] ) );
			$price = $plan['prices'][ $cur ] ?? null;
			if ( null === $price ) {
				throw DomainError::validation( array( 'currency' => 'This plan is not available in ' . $cur . '.' ) );
			}
			return array(
				'mode'           => 'new',
				'plan'           => $plan,
				'currency'       => $cur,
				'amount_minor'   => (int) $price,
				'amount_display' => \WitsValley\Members\Support\Money::format( (int) $price, $cur ),
				'is_upgrade'     => true,
				'note'           => 'Your membership starts as soon as payment is confirmed.',
			);
		}
		return $this->subs->upgradePreview( $sub, $planId, $currency ?: null );
	}

	/** @param array<string,mixed> $plan */
	private function assertJoinable( array $plan ): void {
		if ( $plan['archived_at'] || ( 'public' !== $plan['visibility'] && 'invite' !== $plan['visibility'] ) ) {
			throw DomainError::conflict( 'This plan is not available.' );
		}
		if ( $plan['is_quoted'] ) {
			throw new DomainError( 'This programme is quoted — apply on the Enterprise page.', 'wvm_quoted_plan', 409 );
		}
	}

	/** @return array<string,mixed> */
	private function upgrade( int $planId, string $currency ): array {
		$member = $this->me();
		$plan   = $this->plans->get( $planId );
		$this->assertJoinable( $plan );
		$sub = $this->subRepo->current( (int) $member['id'] );
		if ( $plan['is_free'] ) {
			return array(
				'subscription' => $this->subs->startFree( (int) $member['id'] ),
				'redirect'     => Urls::dashboard(),
			);
		}
		if ( $sub && StateMachine::grantsAccess( (string) $sub['status'] ) && ! $this->plans->get( (int) $sub['plan_id'] )['is_free'] ) {
			$res = $this->subs->changePlan( $sub, $planId, 'immediate', true, 'Member upgrade' );
			return $res + array( 'redirect' => $res['checkout_url'] ?? Urls::dashboard() );
		}
		if ( $sub && in_array( $sub['status'], array( 'suspended', 'expired' ), true ) && (int) $sub['plan_id'] === $planId && $this->subs->withinRejoinWindow( $sub ) ) {
			$order = $this->orders->ensureRenewalOrder( $sub );
			return array(
				'order'        => $order,
				'checkout_url' => $order['checkout_url'],
				'redirect'     => $order['checkout_url'],
			);
		}
		$existing = wvm( OrderRepository::class )->first(
			array(
				'member_id' => (int) $member['id'],
				'plan_id'   => $planId,
				'type'      => 'new',
				'status'    => 'pending',
			)
		);
		$cur      = strtoupper( $currency ?: $this->currencyFor( (string) $member['country'] ) );
		if ( $existing && $existing['currency'] === $cur ) {
			$order = $this->orders->present( $existing );
		} else {
			$order = $this->orders->createForPlan( (int) $member['id'], $planId, 'new', $cur );
		}
		return array(
			'order'        => $order,
			'checkout_url' => $order['checkout_url'],
			'redirect'     => $order['checkout_url'],
		);
	}

	/** @return array<string,mixed> */
	private function downgrade( int $planId ): array {
		$sub  = $this->live();
		$plan = $this->plans->get( $planId );
		if ( $plan['is_free'] ) {
			return array( 'subscription' => $this->subs->cancel( $sub, 'Downgraded to free' ) );
		}
		return $this->subs->changePlan( $sub, $planId, 'at_renewal', false, 'Member downgrade' );
	}

	/** @return array<string,mixed> Pending renewal/reactivation order + checkout URL. */
	private function payNow(): array {
		$member = $this->me();
		$sub    = $this->subRepo->current( (int) $member['id'] );
		if ( ! $sub || $this->plans->get( (int) $sub['plan_id'] )['is_free'] ) {
			throw DomainError::conflict( 'Choose a plan to join first.' );
		}
		if ( 'cancelled' === $sub['status'] || ( 'expired' === $sub['status'] && ! $this->subs->withinRejoinWindow( $sub ) ) ) {
			$plan  = $this->plans->get( (int) $sub['plan_id'] );
			$cur   = isset( $plan['prices'][ (string) $sub['currency'] ] ) ? (string) $sub['currency'] : $this->currencyFor( (string) $member['country'] );
			$order = $this->orders->createForPlan( (int) $member['id'], (int) $sub['plan_id'], 'new', $cur );
		} else {
			$order = $this->orders->ensureRenewalOrder( $sub );
		}
		return array(
			'order'        => $order,
			'checkout_url' => $order['checkout_url'],
			'redirect'     => $order['checkout_url'],
		);
	}

	/** @return array{items:list<array<string,mixed>>, meta:array<string,int>} */
	private function myOrders( Pagination $p ): array {
		$res          = $this->orders->list( array( 'member' => (int) $this->me()['id'] ), $p );
		$res['items'] = array_values(
			array_map(
				static function ( $o ) {
					unset( $o['woo_order_url'], $o['review_reason'], $o['member'] );
					return $o;
				},
				$res['items']
			)
		);
		return $res;
	}

	private function receipt( int $orderId ): never {
		$order = $this->orders->raw( $orderId );
		$me    = $this->me();
		if ( (int) $order['member_id'] !== (int) $me['id'] && ! current_user_can( 'wvm_view_payments' ) ) {
			throw DomainError::notFound( 'order' );
		}
		$receipt = wvm( Receipt::class );
		$path    = $receipt->pdfPath( $orderId );
		if ( $path ) {
			$this->download( (string) file_get_contents( $path ), basename( $path ), 'application/pdf' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $receipt->html( $orderId ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private function orgAdmin(): int {
		$org = wvm( OrganisationService::class )->forMember( (int) $this->me()['id'] );
		if ( ! $org || 'admin' !== $org['role'] ) {
			throw DomainError::forbidden( 'Only your organisation admin can manage seats.' );
		}
		return (int) $org['id'];
	}

	/** @return list<string> */
	public static function emailsFromCsv( string $csv ): array {
		preg_match_all( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $csv, $m );
		return array_values( array_unique( array_map( 'strtolower', $m[0] ) ) );
	}
}
