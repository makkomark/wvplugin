<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Access\MediaGuard;
use WitsValley\Members\Domain\Enterprise\ApplicationService;
use WitsValley\Members\Domain\Enterprise\OrganisationRepository;
use WitsValley\Members\Domain\Enterprise\OrganisationService;
use WitsValley\Members\Domain\Member\MemberService;
use WitsValley\Members\Domain\Notification\Urls;
use WitsValley\Members\Domain\Order\OrderRepository;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Plan\PlanService;
use WitsValley\Members\Domain\Subscription\SubscriptionService;
use WitsValley\Members\Jobs\Scheduler;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Settings;

final class PublicController extends Controller {

	public function __construct(
		private PlanService $plans,
		private MemberService $members,
		private OrderService $orders,
		private Settings $settings
	) {}

	public function routes(): void {
		$p = 'public';
		$this->route( 'GET', '/public/plans', fn( $r ) => $this->publicPlans( (string) $r->get_param( 'currency' ) ), $p );
		$this->route( 'POST', '/public/signup', fn( $r ) => $this->signup( $this->body( $r ) ), $p );
		$this->route( 'POST', '/public/login', fn( $r ) => $this->login( $this->body( $r ) ), $p );
		$this->route( 'POST', '/public/password-reset', fn( $r ) => $this->passwordReset( $this->body( $r ) ), $p );
		$this->route( 'GET', '/public/orders/(?P<token>[a-f0-9]{16,64})/status', fn( $r ) => $this->orderStatus( (string) $r['token'] ), $p );
		$this->route( 'POST', '/public/applications', fn( $r ) => $this->application( $this->body( $r ) ), $p );
		$this->route( 'GET', '/public/geo', fn() => $this->geo(), $p );
		$this->route( 'GET', '/public/media/(?P<token>[A-Za-z0-9_\-\.]+)', fn( $r ) => wvm( MediaGuard::class )->serve( (string) $r['token'] ), $p );
		$this->route( 'GET', '/public/invites/(?P<token>[a-f0-9]{20,64})', fn( $r ) => $this->invite( (string) $r['token'] ), $p );
		$this->route( 'POST', '/public/invites/(?P<token>[a-f0-9]{20,64})/accept', fn( $r ) => $this->acceptInvite( (string) $r['token'], $this->body( $r ) ), $p );
	}

	/** @return list<array<string,mixed>> */
	private function publicPlans( string $currency ): array {
		$currency = strtoupper( $currency ?: $this->geo()['currency'] );
		$key      = 'wvm_public_plans_' . $currency;
		$cached   = wp_cache_get( $key, 'wvm' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$plans = $this->plans->getPublic( $currency );
		wp_cache_set( $key, $plans, 'wvm', 5 * MINUTE_IN_SECONDS );
		return $plans;
	}

	/**
	 * @param array<string,mixed> $b
	 * @return array<string,mixed>
	 */
	private function signup( array $b ): array {
		if ( ! empty( $b['website'] ) ) {
			throw DomainError::validation( array( 'website' => 'Spam detected.' ) );
		}
		if ( empty( $b['terms'] ) ) {
			throw DomainError::validation( array( 'terms' => 'Please accept the terms to continue.' ) );
		}
		$this->rateLimit( 'signup', 10 );
		$plan = $this->plans->get( (int) ( $b['plan_id'] ?? 0 ) );
		if ( $plan['archived_at'] || 'hidden' === $plan['visibility'] ) {
			throw DomainError::validation( array( 'plan_id' => 'This plan is not available.' ) );
		}
		if ( $plan['is_quoted'] ) {
			return array( 'redirect' => Urls::enterprise() );
		}
		$currency = strtoupper( (string) ( $b['currency'] ?? '' ) ) ?: $this->geo()['currency'];
		if ( ! $plan['is_free'] && ! isset( $plan['prices'][ $currency ] ) ) {
			throw DomainError::validation( array( 'currency' => sprintf( '%s is not available in %s.', $plan['name'], $currency ) ) );
		}
		$member = $this->members->createFromSignup(
			array(
				'name'              => $b['name'] ?? '',
				'email'             => $b['email'] ?? '',
				'phone'             => $b['phone'] ?? '',
				'country'           => $b['country'] ?? $this->geo()['country'],
				'password'          => $b['password'] ?? '',
				'timezone'          => $b['timezone'] ?? null,
				'marketing_consent' => ! empty( $b['marketing_consent'] ),
			)
		);
		if ( isset( $b['source'] ) && 'popup' === $b['source'] ) {
			wvm( \WitsValley\Members\Domain\Popup\PopupService::class )->joined( (int) $member['user_id'], (string) ( $b['variant'] ?? 'a' ) );
		}
		wp_set_current_user( (int) $member['user_id'] );
		wp_set_auth_cookie( (int) $member['user_id'], true, is_ssl() );
		update_user_meta( (int) $member['user_id'], 'wvm_known_devices', array( substr( hash( 'sha256', \WitsValley\Members\Frontend\Frontend::describeDevice( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' ) ), 0, 16 ) ) );
		if ( $plan['is_free'] ) {
			wvm( SubscriptionService::class )->startFree( (int) $member['id'] );
			return array(
				'member'   => $member,
				'redirect' => add_query_arg( 'welcome', '1', Urls::dashboard() ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			);
		}
		$order = $this->orders->createForPlan( (int) $member['id'], (int) $plan['id'], 'new', $currency );
		wvm( Scheduler::class )->at( time() + 45 * MINUTE_IN_SECONDS, Scheduler::PENDING_NUDGE, array( (int) $order['id'] ) );
		return array(
			'member'       => $member,
			'order'        => $order,
			'checkout_url' => $order['checkout_url'],
			'redirect'     => $order['checkout_url'],
			'nonce'        => wp_create_nonce( 'wp_rest' ),
		);
	}

	/**
	 * @param array<string,mixed> $b
	 * @return array<string,mixed>
	 */
	private function login( array $b ): array {
		$this->rateLimit( 'login', 20 );
		$login = sanitize_text_field( (string) ( $b['email'] ?? $b['login'] ?? '' ) );
		if ( is_email( $login ) ) {
			$u     = get_user_by( 'email', $login );
			$login = $u ? $u->user_login : $login;
		}
		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => (string) ( $b['password'] ?? '' ),
				'remember'      => ! empty( $b['remember'] ),
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) ) {
			throw new DomainError( 'That email and password do not match.', 'wvm_bad_login', 401 );
		}
		wp_set_current_user( $user->ID );
		return array(
			'user'     => array(
				'id'   => $user->ID,
				'name' => $user->display_name,
			),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'redirect' => Urls::dashboard(),
		);
	}

	/** @param array<string,mixed> $b */
	private function passwordReset( array $b ): array {
		$this->rateLimit( 'reset', 5 );
		$email = sanitize_email( (string) ( $b['email'] ?? '' ) );
		if ( is_email( $email ) && email_exists( $email ) ) {
			retrieve_password( $email );
		}
		return array( 'sent' => true );
	}

	/** @return array<string,mixed> */
	private function orderStatus( string $token ): array {
		$order = wvm( OrderRepository::class )->byToken( $token );
		if ( ! $order ) {
			throw DomainError::notFound( 'order' );
		}
		return array(
			'status' => (string) $order['status'],
			'paid'   => 'paid' === $order['status'],
		);
	}

	/** @param array<string,mixed> $b */
	private function application( array $b ): array {
		if ( ! empty( $b['website'] ) ) {
			throw DomainError::validation( array( 'website' => 'Spam detected.' ) );
		}
		$nonce = (string) ( $b['_nonce'] ?? '' );
		if ( $nonce && ! wp_verify_nonce( $nonce, 'wvm_application' ) && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			throw DomainError::forbidden( 'Your session expired. Reload the page and try again.' );
		}
		unset( $b['website'], $b['_nonce'] );
		$app = wvm( ApplicationService::class )->submit( $b, self::ip() );
		return array(
			'submitted' => true,
			'id'        => $app['id'],
		);
	}

	/** @return array{country:string, currency:string, source:string} */
	public function geo(): array {
		$ip     = self::ip();
		$key    = 'wvm_geo_' . md5( $ip );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$detect   = (array) $this->settings->get( 'general', 'country_detection', array() );
		$provider = (string) ( $detect['provider'] ?? 'woocommerce' );
		$country  = '';
		$source   = $provider;
		if ( isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$country = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
			$source  = 'cloudflare';
		} elseif ( 'ipapi' === $provider && $ip ) {
			$res     = wp_remote_get( 'https://ipapi.co/' . rawurlencode( $ip ) . '/country/', array( 'timeout' => 3 ) );
			$country = is_wp_error( $res ) ? '' : strtoupper( trim( (string) wp_remote_retrieve_body( $res ) ) );
		} elseif ( class_exists( '\WC_Geolocation' ) && $ip ) {
			$geo     = \WC_Geolocation::geolocate_ip( $ip, true, false );
			$country = strtoupper( (string) ( $geo['country'] ?? '' ) );
			$source  = 'woocommerce';
		}
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) || 'XX' === $country ) {
			$country = 'UG';
			$source  = 'default';
		}
		$map      = (array) $this->settings->get( 'payments', 'default_currency_by_country', array() );
		$enabled  = (array) $this->settings->get( 'payments', 'currencies_enabled', array( 'UGX' ) );
		$currency = (string) ( $map[ $country ] ?? $map['default'] ?? ( $enabled[0] ?? 'UGX' ) );
		if ( ! in_array( $currency, $enabled, true ) ) {
			$currency = (string) ( $enabled[0] ?? 'UGX' );
		}
		$out = compact( 'country', 'currency', 'source' );
		set_transient( $key, $out, DAY_IN_SECONDS );
		return $out;
	}

	/** @return array<string,mixed> */
	private function invite( string $token ): array {
		$seat = wvm( OrganisationService::class )->seatByToken( $token );
		if ( ! $seat ) {
			return array( 'valid' => false );
		}
		$org = wvm( OrganisationRepository::class )->find( (int) $seat['organisation_id'] );
		return array(
			'valid'       => (bool) $org && 'active' === $org['status'],
			'org_name'    => $org['name'] ?? '',
			'programme'   => $org['programme_title'] ?? '',
			'email'       => $seat['email'],
			'role'        => $seat['role'],
			'has_account' => (bool) email_exists( (string) $seat['email'] ),
			'joined'      => (bool) $seat['joined_at'],
		);
	}

	/**
	 * Accept an invite as a new user (no account yet).
	 *
	 * @param array<string,mixed> $b name, password
	 * @return array<string,mixed>
	 */
	private function acceptInvite( string $token, array $b ): array {
		$this->rateLimit( 'invite', 10 );
		$seat = wvm( OrganisationService::class )->seatByToken( $token );
		if ( ! $seat ) {
			throw new DomainError( 'This invitation is no longer valid.', 'wvm_invalid_invite', 404 );
		}
		if ( is_user_logged_in() ) {
			$org = wvm( OrganisationService::class )->accept( $token, get_current_user_id() );
			return array(
				'organisation' => $org,
				'redirect'     => Urls::dashboard( 'programme' ),
			);
		}
		if ( email_exists( (string) $seat['email'] ) ) {
			throw new DomainError( 'You already have an account — log in to accept.', 'wvm_login_required', 409 );
		}
		$member = $this->members->createFromSignup(
			array(
				'name'              => $b['name'] ?? '',
				'email'             => $seat['email'],
				'password'          => $b['password'] ?? '',
				'phone'             => $b['phone'] ?? '',
				'country'           => $b['country'] ?? '',
				'marketing_consent' => ! empty( $b['marketing_consent'] ),
			)
		);
		wp_set_current_user( (int) $member['user_id'] );
		wp_set_auth_cookie( (int) $member['user_id'], true, is_ssl() );
		$org = wvm( OrganisationService::class )->accept( $token, (int) $member['user_id'] );
		return array(
			'organisation' => $org,
			'redirect'     => Urls::dashboard( 'programme' ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
		);
	}
}
