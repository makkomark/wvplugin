<?php
declare( strict_types=1 );

namespace WitsValley\Members\Woo;

use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Support\Settings;

/**
 * Registers plugin gateways and filters available gateways by billing country for plugin orders.
 */
final class GatewayFilter {

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'addGateways' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter' ), 50 );
	}

	/**
	 * @param array<int|string,mixed> $gateways
	 * @return array<int|string,mixed>
	 */
	public function addGateways( array $gateways ): array {
		$gateways[] = TestGateway::class;
		$gateways[] = ManualGateway::class;
		return $gateways;
	}

	/**
	 * @param array<string,\WC_Payment_Gateway> $gateways
	 * @return array<string,\WC_Payment_Gateway>
	 */
	public function filter( $gateways ): array {
		$gateways = (array) $gateways;
		$order    = $this->currentOrder();
		if ( ! $order || ! $order->get_meta( OrderService::META ) ) {
			unset( $gateways['wvm_manual'] );
			return $gateways;
		}
		unset( $gateways['wvm_manual'] );
		$country = strtoupper( $order->get_billing_country() ?: 'default' );
		return $this->forCurrency( $this->forCountry( $gateways, $country ), strtoupper( (string) $order->get_currency( 'edit' ) ) );
	}

	/**
	 * @param array<string,\WC_Payment_Gateway> $gateways
	 * @return array<string,\WC_Payment_Gateway>
	 */
	public function forCountry( array $gateways, string $country ): array {
		$map     = (array) $this->settings->get( 'payments', 'gateways_by_country', array() );
		$allowed = (array) ( $map[ $country ] ?? $map['default'] ?? array() );
		if ( (bool) $this->settings->get( 'payments', 'sandbox_mode', false ) ) {
			$allowed[] = 'wvm_test';
		} else {
			unset( $gateways['wvm_test'] );
		}
		if ( ! $allowed ) {
			return $gateways;
		}
		$out = array();
		foreach ( $allowed as $id ) {
			if ( isset( $gateways[ $id ] ) ) {
				$out[ $id ] = $gateways[ $id ];
			}
		}
		// Never leave a member with no way to pay: fall back to everything enabled.
		return $out ?: $gateways;
	}

	/**
	 * Drop gateways that cannot take the order's currency (Settings › Payments › Gateway currencies).
	 * A gateway with no list is assumed to accept any currency. Never falls back to unsupported gateways:
	 * charging a UGX amount in another currency is worse than showing "no payment method".
	 *
	 * @param array<string,\WC_Payment_Gateway> $gateways
	 * @return array<string,\WC_Payment_Gateway>
	 */
	public function forCurrency( array $gateways, string $currency ): array {
		$map = (array) $this->settings->get( 'payments', 'gateway_currencies', array() );
		return array_filter(
			$gateways,
			static function ( $gw, $id ) use ( $map, $currency ): bool {
				$list = array_map( 'strtoupper', (array) ( $map[ $id ] ?? array() ) );
				return ! $list || in_array( $currency, $list, true );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	private function currentOrder(): ?\WC_Order {
		global $wp;
		$id = 0;
		if ( isset( $wp->query_vars['order-pay'] ) ) {
			$id = absint( $wp->query_vars['order-pay'] );
		} elseif ( isset( $_GET['pay_for_order'], $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$id = wc_get_order_id_by_order_key( sanitize_text_field( wp_unslash( $_GET['key'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! $id && isset( $_POST['woocommerce_pay'], $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$id = wc_get_order_id_by_order_key( sanitize_text_field( wp_unslash( $_GET['key'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$order = $id ? wc_get_order( $id ) : null;
		return $order instanceof \WC_Order ? $order : null;
	}
}
