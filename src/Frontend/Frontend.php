<?php
declare( strict_types=1 );

namespace WitsValley\Members\Frontend;

use WitsValley\Members\Domain\Access\ContentGuard;
use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Member\MemberService;
use WitsValley\Members\Domain\Notification\Mailer;
use WitsValley\Members\Domain\Notification\Urls;
use WitsValley\Members\Domain\Order\OrderRepository;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Encryption;
use WitsValley\Members\Support\Settings;

/**
 * Shortcodes, pretty routes, member app bootstrapping, impersonation and new-device alerts.
 */
final class Frontend {

	public const PAY_LINK_TTL = 7 * DAY_IN_SECONDS;

	private bool $needsApp = false;

	public function __construct( private Settings $settings, private OrderRepository $orders ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'rewrites' ) );
		add_filter( 'query_vars', array( $this, 'queryVars' ) );
		add_action( 'template_redirect', array( $this, 'routes' ), 0 );
		add_action( 'init', array( $this, 'impersonation' ), 1 );
		add_action( 'wp_footer', array( $this, 'impersonationBanner' ) );
		add_shortcode( 'wvm_dashboard', fn( $a ) => $this->mount( 'dashboard', (array) $a ) );
		add_shortcode( 'wvm_pricing', fn( $a ) => $this->mount( 'pricing', (array) $a ) );
		add_shortcode( 'wvm_enterprise_form', fn( $a ) => $this->mount( 'enterprise', (array) $a ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'publicAssets' ) );
		add_action( 'wp_login', array( $this, 'onLogin' ), 10, 2 );
		add_filter( 'show_admin_bar', array( $this, 'adminBar' ) );
		add_filter( 'display_post_states', array( $this, 'postStates' ), 10, 2 );
		add_filter( 'body_class', array( $this, 'bodyClass' ) );
	}

	public function rewrites(): void {
		add_rewrite_rule( '^pay/([a-f0-9]{16,64})/?$', 'index.php?wvm_pay=$matches[1]', 'top' );
		add_rewrite_rule( '^enterprise/accept/([a-f0-9]{20,64})/?$', 'index.php?wvm_accept=$matches[1]', 'top' );
	}

	/** @param list<string> $vars */
	public function queryVars( array $vars ): array {
		$vars[] = 'wvm_pay';
		$vars[] = 'wvm_accept';
		return $vars;
	}

	public function routes(): void {
		$pay = (string) get_query_var( 'wvm_pay' );
		if ( '' !== $pay ) {
			$this->payLink( $pay );
		}
		$accept = (string) get_query_var( 'wvm_accept' );
		if ( '' !== $accept ) {
			nocache_headers();
			wp_safe_redirect( add_query_arg( 'invite', rawurlencode( $accept ), Urls::dashboard() ) );
			exit;
		}
	}

	/** /pay/{token}: single-use login by token (7-day expiry) → WooCommerce order-pay page. */
	private function payLink( string $token ): void {
		nocache_headers();
		$order = $this->orders->byToken( $token );
		if ( ! $order ) {
			wp_safe_redirect( add_query_arg( 'pay', 'invalid', Urls::dashboard() ) );
			exit;
		}
		if ( 'paid' === $order['status'] ) {
			wp_safe_redirect( add_query_arg( 'welcome', '1', Urls::dashboard() ) );
			exit;
		}
		if ( ! in_array( $order['status'], array( 'pending', 'failed' ), true ) ) {
			wp_safe_redirect( add_query_arg( 'pay', 'closed', Urls::dashboard() ) );
			exit;
		}
		$wc = $order['woo_order_id'] ? wc_get_order( (int) $order['woo_order_id'] ) : null;
		if ( ! $wc ) {
			wp_safe_redirect( add_query_arg( 'pay', 'invalid', Urls::dashboard() ) );
			exit;
		}
		if ( $wc->has_status( 'failed' ) ) {
			$wc->update_status( 'pending', 'Retry via WitsValley pay link.' );
			$this->orders->update( (int) $order['id'], array( 'status' => 'pending' ) );
		}
		$checkout = $wc->get_checkout_payment_url();
		$member   = wvm( MemberService::class )->get( (int) $order['member_id'] );
		$uid      = (int) $member['user_id'];
		$expired  = ( time() - (int) strtotime( $order['created_at'] . ' UTC' ) ) > self::PAY_LINK_TTL;
		if ( is_user_logged_in() ) {
			if ( get_current_user_id() === $uid ) {
				wp_safe_redirect( $checkout );
				exit;
			}
			wp_logout();
		}
		$staff = user_can( $uid, 'manage_options' ) || user_can( $uid, 'wvm_access' ) || user_can( $uid, 'edit_posts' );
		if ( $expired || $order['pay_link_used_at'] || $staff ) {
			wp_safe_redirect( wp_login_url( $checkout ) );
			exit;
		}
		$this->orders->update( (int) $order['id'], array( 'pay_link_used_at' => Clock::mysql() ) );
		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, false, is_ssl() );
		wvm( Audit::class )->log( 'order.pay_link_login', 'order', (int) $order['id'] );
		wp_safe_redirect( $checkout );
		exit;
	}

	/**
	 * Render a mount point for the member app.
	 *
	 * @param array<string,mixed> $atts
	 */
	public function mount( string $view, array $atts ): string {
		$this->needsApp = true;
		$this->enqueueApp();
		$plan = isset( $atts['plan'] ) ? sanitize_key( (string) $atts['plan'] ) : '';
		return '<div class="wvm-root" data-wvm-view="' . esc_attr( $view ) . '" data-plan="' . esc_attr( $plan ) . '"><noscript>' . esc_html__( 'Please enable JavaScript to use your WitsValley dashboard.', 'wv-members' ) . '</noscript><div class="wvm-boot" aria-busy="true"><span class="wvm-boot__dot"></span><span class="wvm-boot__dot"></span><span class="wvm-boot__dot"></span></div></div>';
	}

	public function enqueueApp(): void {
		$asset_file = WVM_DIR . 'build/member.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'react', 'react-dom', 'wp-element' ),
			'version'      => WVM_VERSION,
		);
		wp_enqueue_script( 'wvm-member', WVM_URL . 'build/member.js', (array) $asset['dependencies'], (string) $asset['version'], true );
		if ( file_exists( WVM_DIR . 'build/member.css' ) ) {
			wp_enqueue_style( 'wvm-member', WVM_URL . 'build/member.css', array(), (string) $asset['version'] );
		}
		wp_localize_script( 'wvm-member', 'wvmMember', $this->config() );
	}

	/** @return array<string,mixed> */
	public function config(): array {
		$user     = wp_get_current_user();
		$payments = $this->settings->group( 'payments' );
		$general  = $this->settings->group( 'general' );
		return array(
			'restUrl'         => esc_url_raw( rest_url( WVM_REST_NS . '/' ) ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'isLoggedIn'      => is_user_logged_in(),
			'user'            => $user->ID ? array(
				'id'      => $user->ID,
				'name'    => $user->display_name,
				'email'   => $user->user_email,
				'isStaff' => current_user_can( 'wvm_access' ) || current_user_can( 'manage_options' ),
			) : null,
			'siteName'        => (string) $general['business_name'],
			'homeUrl'         => home_url( '/' ),
			'dashboardUrl'    => Urls::dashboard(),
			'pricingUrl'      => Urls::pricing(),
			'enterpriseUrl'   => Urls::enterprise(),
			'loginUrl'        => wp_login_url( Urls::dashboard() ),
			'logoutUrl'       => wp_logout_url( home_url( '/' ) ),
			'lostPasswordUrl' => wp_lostpassword_url( Urls::dashboard() ),
			'adminUrl'        => current_user_can( 'wvm_access' ) || current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=wvm' ) : null,
			'termsUrl'        => (string) $general['terms_url'],
			'privacyUrl'      => (string) ( $general['privacy_url'] ?: get_privacy_policy_url() ),
			'supportEmail'    => (string) ( $general['support_email'] ?: get_option( 'admin_email' ) ),
			'currencies'      => array_values( (array) $payments['currencies_enabled'] ),
			'defaultCurrency' => (array) $payments['default_currency_by_country'],
			'sandbox'         => (bool) $payments['sandbox_mode'],
			'analytics'       => (bool) $general['analytics'],
			'countries'       => function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array(),
			'timezones'       => timezone_identifiers_list(),
			'logoUrl'         => (string) $this->settings->get( 'emails', 'logo_url', '' ),
			'branding'        => wvm( \WitsValley\Members\Support\Branding::class )->forApp(),
			'version'         => WVM_VERSION,
		);
	}

	public function publicAssets(): void {
		wp_enqueue_style( 'wvm-public', WVM_URL . 'assets/css/public.css', array(), WVM_VERSION );
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && ( has_shortcode( (string) $post->post_content, 'wvm_dashboard' ) || has_shortcode( (string) $post->post_content, 'wvm_pricing' ) || has_shortcode( (string) $post->post_content, 'wvm_enterprise_form' ) ) ) {
				$this->enqueueApp();
			}
		}
	}

	/**
	 * Mark plugin app pages so the theme's own page title can be hidden (the apps render their own headers).
	 *
	 * @param list<string> $classes
	 * @return list<string>
	 */
	public function bodyClass( array $classes ): array {
		$post = is_singular() ? get_post() : null;
		if ( $post ) {
			foreach ( array(
				'wvm_dashboard'       => 'dashboard',
				'wvm_pricing'         => 'pricing',
				'wvm_enterprise_form' => 'enterprise',
			) as $code => $view ) {
				if ( has_shortcode( (string) $post->post_content, $code ) ) {
					$classes[] = 'wvm-app-page';
					$classes[] = 'wvm-app-page--' . $view;
				}
			}
		}
		return $classes;
	}

	/* ------------------------------------------------------------------
	 * Impersonation
	 * ---------------------------------------------------------------- */

	public function impersonation(): void {
		if ( isset( $_GET['wvm_impersonate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$token = sanitize_text_field( wp_unslash( $_GET['wvm_impersonate'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$key   = 'wvm_imp_' . hash( 'sha256', $token );
			$data  = get_transient( $key );
			delete_transient( $key );
			if ( ! is_array( $data ) || get_current_user_id() !== (int) $data['admin_id'] || ! user_can( (int) $data['admin_id'], 'wvm_impersonate' ) ) {
				wp_die( esc_html__( 'This impersonation link is invalid or expired.', 'wv-members' ), '', array( 'response' => 403 ) );
			}
			$sig = Encryption::sign( 'imp|' . (int) $data['admin_id'] . '|' . (int) $data['user_id'] );
			wp_clear_auth_cookie();
			wp_set_current_user( (int) $data['user_id'] );
			wp_set_auth_cookie( (int) $data['user_id'], false, is_ssl() );
			setcookie( 'wvm_imp', (int) $data['admin_id'] . '|' . (int) $data['user_id'] . '|' . $sig, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			wvm( Audit::class )->log( 'member.impersonation_entered', 'member', (int) $data['member_id'] );
			wp_safe_redirect( Urls::dashboard() );
			exit;
		}
		if ( isset( $_GET['wvm_unimpersonate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$imp = $this->impersonator();
			if ( $imp ) {
				$member_id = (int) ( wvm( MemberService::class )->byUser( get_current_user_id() )['id'] ?? 0 );
				wp_clear_auth_cookie();
				wp_set_current_user( $imp );
				wp_set_auth_cookie( $imp, false, is_ssl() );
				setcookie( 'wvm_imp', '', time() - HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
				wvm( Audit::class )->log( 'member.impersonation_ended', 'member', $member_id );
				wp_safe_redirect( admin_url( 'admin.php?page=wvm-members#/members/' . $member_id ) );
				exit;
			}
		}
	}

	/** Admin user id if the current session is an impersonation. */
	public function impersonator(): ?int {
		if ( empty( $_COOKIE['wvm_imp'] ) ) {
			return null;
		}
		$parts = explode( '|', sanitize_text_field( wp_unslash( $_COOKIE['wvm_imp'] ) ) );
		if ( 3 !== count( $parts ) || (int) $parts[1] !== get_current_user_id() ) {
			return null;
		}
		$expected = Encryption::sign( 'imp|' . (int) $parts[0] . '|' . (int) $parts[1] );
		return hash_equals( $expected, $parts[2] ) ? (int) $parts[0] : null;
	}

	public function impersonationBanner(): void {
		$admin = $this->impersonator();
		if ( ! $admin ) {
			return;
		}
		$user = wp_get_current_user();
		printf(
			'<div class="wvm-imp-banner" role="status">%s <a href="%s">%s</a></div>',
			esc_html( sprintf( /* translators: %s member name */ __( 'You are viewing the site as %s.', 'wv-members' ), $user->display_name ) ),
			esc_url( add_query_arg( 'wvm_unimpersonate', '1', home_url( '/' ) ) ),
			esc_html__( 'Return to admin', 'wv-members' )
		);
	}

	public function adminBar( bool $show ): bool {
		if ( $this->impersonator() ) {
			return false;
		}
		return $show && ( current_user_can( 'edit_posts' ) || current_user_can( 'wvm_access' ) );
	}

	/* ------------------------------------------------------------------
	 * New device login alert
	 * ---------------------------------------------------------------- */

	public function onLogin( string $login, \WP_User $user ): void {
		unset( $login );
		if ( $this->impersonator() || user_can( $user, 'wvm_access' ) || user_can( $user, 'manage_options' ) ) {
			return;
		}
		$member = wvm( MemberService::class )->byUser( (int) $user->ID );
		if ( ! $member ) {
			return;
		}
		$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$device = self::describeDevice( $ua );
		$hash   = substr( hash( 'sha256', $device ), 0, 16 );
		$known  = (array) get_user_meta( (int) $user->ID, 'wvm_known_devices', true );
		if ( in_array( $hash, $known, true ) ) {
			return;
		}
		$known[] = $hash;
		update_user_meta( (int) $user->ID, 'wvm_known_devices', array_slice( array_values( array_filter( $known ) ), -10 ) );
		if ( count( $known ) > 1 ) {
			wvm( Mailer::class )->send(
				'new_device_login',
				(int) $member['id'],
				array(
					'device' => $device,
					'ip'     => $ip,
				)
			);
		}
	}

	public static function describeDevice( string $ua ): string {
		$browser = 'Browser';
		foreach ( array(
			'Edg'     => 'Edge',
			'OPR'     => 'Opera',
			'Chrome'  => 'Chrome',
			'Firefox' => 'Firefox',
			'Safari'  => 'Safari',
		) as $needle => $name ) {
			if ( str_contains( $ua, $needle ) ) {
				$browser = $name;
				break;
			}
		}
		$os = 'Unknown OS';
		foreach ( array(
			'Android' => 'Android',
			'iPhone'  => 'iPhone',
			'iPad'    => 'iPad',
			'Windows' => 'Windows',
			'Mac OS'  => 'macOS',
			'Linux'   => 'Linux',
		) as $needle => $name ) {
			if ( str_contains( $ua, $needle ) ) {
				$os = $name;
				break;
			}
		}
		return $browser . ' on ' . $os;
	}

	/**
	 * @param array<string,string> $states
	 * @return array<string,string>
	 */
	public function postStates( array $states, \WP_Post $post ): array {
		$map = array(
			'dashboard_page_id'  => __( 'WitsValley Dashboard', 'wv-members' ),
			'pricing_page_id'    => __( 'WitsValley Membership', 'wv-members' ),
			'enterprise_page_id' => __( 'WitsValley Enterprise', 'wv-members' ),
		);
		foreach ( $map as $key => $label ) {
			if ( (int) $this->settings->get( 'general', $key, 0 ) === (int) $post->ID ) {
				$states[ 'wvm_' . $key ] = $label;
			}
		}
		if ( 'page' !== $post->post_type && wvm( ContentGuard::class )->isProtected( (int) $post->ID ) ) {
			$states['wvm_protected'] = __( 'Members only', 'wv-members' );
		}
		return $states;
	}
}
