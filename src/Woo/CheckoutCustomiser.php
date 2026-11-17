<?php
declare( strict_types=1 );

namespace WitsValley\Members\Woo;

use WitsValley\Members\Domain\Order\OrderRepository;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Plan\PlanService;
use WitsValley\Members\Support\Settings;

/**
 * Branded, trimmed order-pay page for plugin orders.
 */
final class CheckoutCustomiser {

	public function __construct( private OrderRepository $orders, private Settings $settings ) {}

	public function register(): void {
		add_filter( 'woocommerce_locate_template', array( $this, 'locateTemplate' ), 20, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'body_class', array( $this, 'bodyClass' ) );
		add_filter( 'woocommerce_order_button_text', array( $this, 'buttonText' ) );
		add_filter( 'woocommerce_pay_order_button_text', array( $this, 'buttonText' ) );
		add_filter( 'woocommerce_get_terms_and_conditions_checkbox_text', array( $this, 'termsText' ) );
		add_filter( 'woocommerce_checkout_show_terms', array( $this, 'showTerms' ) );
		// WooCommerce "Coming soon" mode must never block a member paying for their membership.
		add_filter( 'woocommerce_coming_soon_exclude', array( $this, 'excludeFromComingSoon' ) );
	}

	public function isPluginOrderPay(): bool {
		if ( ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() ) {
			return false;
		}
		$order = $this->currentOrder();
		return $order && (bool) $order->get_meta( OrderService::META );
	}

	public function currentOrder(): ?\WC_Order {
		global $wp;
		$id    = absint( $wp->query_vars['order-pay'] ?? 0 );
		$order = $id ? wc_get_order( $id ) : null;
		return $order instanceof \WC_Order ? $order : null;
	}

	public function locateTemplate( string $template, string $name, string $path ): string {
		unset( $path );
		if ( 'checkout/form-pay.php' === $name && $this->isPluginOrderPay() ) {
			return WVM_DIR . 'templates/woo/form-pay.php';
		}
		return $template;
	}

	/** @param list<string> $classes */
	public function bodyClass( array $classes ): array {
		if ( $this->isPluginOrderPay() ) {
			$classes[] = 'wvm-checkout';
		}
		return $classes;
	}

	public function assets(): void {
		if ( ! $this->isPluginOrderPay() ) {
			return;
		}
		wp_enqueue_style( 'wvm-checkout', WVM_URL . 'assets/css/checkout.css', array(), WVM_VERSION );
		$order = $this->currentOrder();
		$row   = $order ? $this->orders->find( (int) $order->get_meta( OrderService::META ) ) : null;
		if ( $row ) {
			wp_enqueue_script( 'wvm-checkout', WVM_URL . 'assets/js/checkout.js', array(), WVM_VERSION, true );
			wp_localize_script(
				'wvm-checkout',
				'wvmCheckout',
				array(
					'statusUrl'    => rest_url( WVM_REST_NS . '/public/orders/' . $row['pay_link_token'] . '/status' ),
					'dashboardUrl' => \WitsValley\Members\Domain\Notification\Urls::dashboard(),
					'analytics'    => (bool) $this->settings->get( 'general', 'analytics', true ),
					'amount'       => (int) $row['amount_minor'],
					'currency'     => (string) $row['currency'],
				)
			);
		}
	}

	public function excludeFromComingSoon( bool $exclude ): bool {
		if ( $exclude ) {
			return true;
		}
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		return $this->isPluginOrderPay() || is_order_received_page() || 1 === preg_match( '#/(pay|enterprise/accept)/[a-f0-9]+/?$#', $path );
	}

	public function buttonText( string $text ): string {
		return $this->isPluginOrderPay() ? __( 'Pay securely', 'wv-members' ) : $text;
	}

	public function termsText( string $text ): string {
		return $text;
	}

	public function showTerms( bool $show ): bool {
		return $this->isPluginOrderPay() ? false : $show;
	}

	/**
	 * Data for the template.
	 *
	 * @return array<string,mixed>
	 */
	public function summary( \WC_Order $order ): array {
		$row  = $this->orders->find( (int) $order->get_meta( OrderService::META ) );
		$plan = $row && $row['plan_id'] ? wvm( PlanService::class )->get( (int) $row['plan_id'] ) : null;
		$o    = $row ? wvm( OrderService::class )->get( (int) $row['id'] ) : null;
		return array(
			'order'    => $o,
			'plan'     => $plan,
			'business' => (string) $this->settings->get( 'general', 'business_name', 'WitsValley' ),
			'logo'     => (string) $this->settings->get( 'emails', 'logo_url', '' ),
			'sandbox'  => (bool) $this->settings->get( 'payments', 'sandbox_mode', false ),
			'terms'    => (string) $this->settings->get( 'general', 'terms_url', '' ),
		);
	}
}
