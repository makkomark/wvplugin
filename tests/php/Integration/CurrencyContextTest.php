<?php
namespace WitsValley\Members\Tests\Integration;

use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Woo\CurrencyContext;
use WitsValley\Members\Woo\GatewayFilter;
use WitsValley\Members\Tests\TestCase;

/**
 * Regression: a gateway that reads get_woocommerce_currency() (the store currency, USD) instead of the
 * order currency charged a UGX 20,000 membership as USD 20,000.
 */
final class CurrencyContextTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'USD' );
	}

	public function tear_down(): void {
		unset( $_GET['key'] );
		update_option( 'woocommerce_currency', 'UGX' );
		parent::tear_down();
	}

	public function test_store_currency_follows_plugin_order_during_payment(): void {
		$member = $this->member();
		$order  = wvm( OrderService::class )->createForPlan( (int) $member['id'], (int) $this->plan( 'basic' )['id'], 'new', 'UGX' );
		$wc     = wc_get_order( (int) $order['woo_order_id'] );
		$this->assertSame( 'UGX', $wc->get_currency() );
		$this->assertSame( '20000.00', number_format( (float) $wc->get_total(), 2, '.', '' ) );

		CurrencyContext::reset();
		$this->assertSame( 'USD', get_woocommerce_currency(), 'Outside a payment the store currency is untouched.' );

		// The order-pay page / pay form POST carries the order key.
		$_GET['key'] = $wc->get_order_key();
		CurrencyContext::reset();
		$this->assertSame( 'UGX', get_woocommerce_currency(), 'A gateway reading the store currency now sees UGX.' );
		$this->assertSame( 0, wc_get_price_decimals(), 'UGX has no decimals.' );
	}

	public function test_non_plugin_orders_are_untouched(): void {
		$wc = wc_create_order();
		$wc->set_currency( 'UGX' );
		$wc->save();
		$_GET['key'] = $wc->get_order_key();
		CurrencyContext::reset();
		$this->assertSame( 'USD', get_woocommerce_currency() );
	}

	public function test_forced_context_for_background_charges(): void {
		CurrencyContext::reset();
		$this->assertSame( 'KES', CurrencyContext::with( 'KES', static fn() => get_woocommerce_currency() ) );
		$this->assertSame( 'USD', get_woocommerce_currency() );
	}

	public function test_gateways_that_cannot_take_the_currency_are_hidden(): void {
		$filter = wvm( GatewayFilter::class );
		$gws    = array(
			'paypal' => new \stdClass(),
			'jpesa'  => new \stdClass(),
		);
		$this->assertSame( array( 'jpesa' ), array_keys( $filter->forCurrency( $gws, 'UGX' ) ), 'PayPal is not offered for UGX.' );
		$this->assertSame( array( 'paypal', 'jpesa' ), array_keys( $filter->forCurrency( $gws, 'USD' ) ) );
	}
}
