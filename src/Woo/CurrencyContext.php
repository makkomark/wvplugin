<?php
declare( strict_types=1 );

namespace WitsValley\Members\Woo;

use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Support\Money;

/**
 * Makes WooCommerce report the ORDER's currency while a WitsValley order is being paid.
 *
 * Many gateway plugins send `get_woocommerce_currency()` (the store currency, often USD) with the
 * order total instead of `$order->get_currency()`. A UGX 20,000 membership would then be charged as
 * USD 20,000. While a plugin order is in context (order-pay page, the pay form POST, the thank-you
 * page, gateway callbacks carrying the order key, auto-charges) the store currency and price decimals
 * follow the order.
 */
final class CurrencyContext {

	/** Currency forced by code (e.g. auto-charge), takes precedence over request detection. */
	private static ?string $forced = null;

	/** Resolved currency for this request: false = not resolved yet, null = no plugin order. */
	private static string|null|false $resolved = false;

	private static bool $resolving = false;

	public function register(): void {
		add_filter( 'woocommerce_currency', array( $this, 'filterCurrency' ), 999 );
		add_filter( 'wc_get_price_decimals', array( $this, 'filterDecimals' ), 999 );
	}

	/**
	 * Run $fn with WooCommerce's currency set to $currency.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public static function with( string $currency, callable $fn ): mixed {
		$prev         = self::$forced;
		self::$forced = strtoupper( $currency );
		try {
			return $fn();
		} finally {
			self::$forced = $prev;
		}
	}

	public static function reset(): void {
		self::$forced   = null;
		self::$resolved = false;
	}

	public function filterCurrency( $currency ) {
		$override = $this->current();
		return $override ?? $currency;
	}

	public function filterDecimals( $decimals ) {
		$override = $this->current();
		return null !== $override ? Money::decimals( $override ) : $decimals;
	}

	/** Currency of the plugin order in context, or null. */
	public function current(): ?string {
		if ( null !== self::$forced ) {
			return self::$forced;
		}
		if ( false !== self::$resolved ) {
			return self::$resolved;
		}
		if ( self::$resolving || ! function_exists( 'wc_get_order' ) || ! did_action( 'woocommerce_init' ) ) {
			return null;
		}
		self::$resolving = true;
		try {
			$order    = $this->orderFromRequest();
			$currency = ( $order && $order->get_meta( OrderService::META ) ) ? strtoupper( (string) $order->get_currency( 'edit' ) ) : null;
		} finally {
			self::$resolving = false;
		}
		// Don't cache "no plugin order" before WordPress has parsed the request (query vars are not known yet).
		if ( null !== $currency || did_action( 'wp' ) || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			self::$resolved = $currency;
		}
		return $currency;
	}

	private function orderFromRequest(): ?\WC_Order {
		// phpcs:disable WordPress.Security.NonceVerification
		$id = 0;
		if ( isset( $_GET['key'] ) ) {
			$id = (int) wc_get_order_id_by_order_key( sanitize_text_field( wp_unslash( $_GET['key'] ) ) );
		}
		if ( ! $id ) {
			global $wp;
			if ( $wp instanceof \WP && ! empty( $wp->query_vars['order-pay'] ) ) {
				$id = absint( $wp->query_vars['order-pay'] );
			} elseif ( $wp instanceof \WP && ! empty( $wp->query_vars['order-received'] ) ) {
				$id = absint( $wp->query_vars['order-received'] );
			} elseif ( isset( $_GET['order-pay'] ) ) {
				$id = absint( $_GET['order-pay'] );
			}
		}
		if ( ! $id && isset( $_REQUEST['order_id'] ) && is_numeric( $_REQUEST['order_id'] ) ) {
			// Common in gateway return/callback URLs.
			$id = absint( $_REQUEST['order_id'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification
		if ( ! $id ) {
			return null;
		}
		$order = wc_get_order( $id );
		return $order instanceof \WC_Order ? $order : null;
	}
}
