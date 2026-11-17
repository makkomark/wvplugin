<?php
declare( strict_types=1 );

namespace WitsValley\Members\Woo;

use WitsValley\Members\Domain\Notification\Urls;
use WitsValley\Members\Domain\Order\OrderRepository;
use WitsValley\Members\Domain\Order\OrderService;

/**
 * Hides the WooCommerce storefront: account → dashboard, thank-you → dashboard, shop/cart/product → membership.
 */
final class Redirects {

	/** My-account endpoints → dashboard routes. */
	private const ACCOUNT_MAP = array(
		'orders'          => 'billing',
		'view-order'      => 'billing',
		'payment-methods' => 'billing',
		'edit-account'    => 'profile',
		'edit-address'    => 'profile',
		'downloads'       => '',
	);

	public function __construct( private OrderRepository $orders ) {}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'redirect' ), 1 );
		add_filter( 'woocommerce_login_redirect', array( $this, 'loginRedirect' ), 99, 2 );
		add_filter( 'login_redirect', array( $this, 'wpLoginRedirect' ), 99, 3 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'hideMenuItems' ) );
		add_filter( 'get_pages', array( $this, 'hidePages' ) );
		add_filter( 'wp_sitemaps_post_types', array( $this, 'hideSitemapTypes' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'hideSitemapPages' ), 10, 2 );
		add_filter( 'woocommerce_get_myaccount_page_permalink', array( $this, 'accountPermalink' ) );
		add_filter( 'woocommerce_return_to_shop_redirect', static fn() => Urls::pricing() );
	}

	public function redirect(): void {
		if ( ! function_exists( 'is_woocommerce' ) || wp_doing_ajax() ) {
			return;
		}
		global $wp;
		if ( is_order_received_page() ) {
			$this->thankYou( absint( $wp->query_vars['order-received'] ?? 0 ) );
			return;
		}
		if ( is_checkout_pay_page() ) {
			return;
		}
		if ( is_account_page() ) {
			if ( is_wc_endpoint_url( 'lost-password' ) || isset( $_GET['action'] ) || isset( $_GET['key'] ) || isset( $_GET['show-reset-form'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return;
			}
			if ( is_wc_endpoint_url( 'customer-logout' ) ) {
				return;
			}
			if ( ! is_user_logged_in() ) {
				wp_safe_redirect( Urls::dashboard() );
				exit;
			}
			$route = '';
			foreach ( self::ACCOUNT_MAP as $endpoint => $target ) {
				if ( is_wc_endpoint_url( $endpoint ) ) {
					$route = $target;
					break;
				}
			}
			wp_safe_redirect( Urls::dashboard( $route ) );
			exit;
		}
		if ( is_checkout() || is_cart() || is_shop() || is_product() || is_product_category() || is_product_tag() || is_product_taxonomy() ) {
			wp_safe_redirect( Urls::pricing() );
			exit;
		}
	}

	private function thankYou( int $wooId ): void {
		$order = $wooId ? $this->orders->byWoo( $wooId ) : null;
		if ( ! $order ) {
			$wc    = $wooId ? wc_get_order( $wooId ) : null;
			$meta  = $wc ? (int) $wc->get_meta( OrderService::META ) : 0;
			$order = $meta ? $this->orders->find( $meta ) : null;
		}
		if ( ! $order ) {
			return;
		}
		$fresh = $this->orders->find( (int) $order['id'] );
		$base  = Urls::dashboard();
		if ( $fresh && 'paid' === $fresh['status'] ) {
			wp_safe_redirect( add_query_arg( 'welcome', '1', $base ) );
		} else {
			wp_safe_redirect( add_query_arg( 'pending', rawurlencode( (string) $order['pay_link_token'] ), $base ) );
		}
		exit;
	}

	/** @param mixed $user */
	public function loginRedirect( string $redirect, $user = null ): string {
		if ( $user instanceof \WP_User && ( user_can( $user, 'manage_options' ) || user_can( $user, 'wvm_access' ) ) ) {
			return $redirect;
		}
		$account = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';
		return ( ! $redirect || $redirect === $account || str_contains( $redirect, 'my-account' ) ) ? Urls::dashboard() : $redirect;
	}

	/** @param mixed $user */
	public function wpLoginRedirect( string $redirect, string $requested, $user ): string {
		if ( ! $user instanceof \WP_User || user_can( $user, 'manage_options' ) || user_can( $user, 'wvm_access' ) || user_can( $user, 'edit_posts' ) ) {
			return $redirect;
		}
		if ( ! $requested || str_contains( $requested, 'wp-admin' ) || str_contains( $requested, 'my-account' ) ) {
			return Urls::dashboard();
		}
		return $redirect;
	}

	public function accountPermalink( string $url ): string {
		return is_admin() ? $url : Urls::dashboard();
	}

	/** @return list<int> */
	private function wooPageIds(): array {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', array( wc_get_page_id( 'shop' ), wc_get_page_id( 'cart' ), wc_get_page_id( 'checkout' ), wc_get_page_id( 'myaccount' ), wc_get_page_id( 'refund_returns' ) ) ), static fn( $id ) => $id > 0 ) );
	}

	/**
	 * @param array<int,\WP_Post> $items
	 * @return array<int,\WP_Post>
	 */
	public function hideMenuItems( array $items ): array {
		$hidden = $this->wooPageIds();
		return array_filter( $items, static fn( $i ) => ! ( 'page' === $i->object && in_array( (int) $i->object_id, $hidden, true ) ) );
	}

	/**
	 * @param array<int,\WP_Post> $pages
	 * @return array<int,\WP_Post>
	 */
	public function hidePages( array $pages ): array {
		if ( is_admin() ) {
			return $pages;
		}
		$hidden = $this->wooPageIds();
		return array_values( array_filter( $pages, static fn( $p ) => ! in_array( (int) $p->ID, $hidden, true ) ) );
	}

	/**
	 * @param array<string,mixed> $types
	 * @return array<string,mixed>
	 */
	public function hideSitemapTypes( array $types ): array {
		unset( $types['product'] );
		return $types;
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function hideSitemapPages( array $args, string $type ): array {
		if ( 'page' === $type ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), $this->wooPageIds() );
		}
		return $args;
	}
}
