<?php
declare( strict_types=1 );

namespace WitsValley\Members\Woo;

use WitsValley\Members\Jobs\Scheduler;
use WitsValley\Members\Support\Settings;

/**
 * Sandbox-only gateway with Approve / Decline / Pending (async) outcomes and optional saved card.
 */
final class TestGateway extends \WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'wvm_test';
		$this->method_title       = 'WitsValley Test gateway';
		$this->method_description = 'Sandbox-only gateway for testing the join and renewal flows. Only available while WitsValley sandbox mode is on.';
		$this->title              = 'Test payment (sandbox)';
		$this->description        = 'No real money moves. Choose the outcome to simulate.';
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds', 'tokenization' );
		$this->enabled            = 'yes';
		$this->init_form_fields();
		$this->init_settings();
		$this->enabled = 'yes';
	}

	public function init_form_fields(): void {
		$this->form_fields = array();
	}

	public function is_available(): bool {
		return (bool) wvm( Settings::class )->get( 'payments', 'sandbox_mode', false );
	}

	public function payment_fields(): void {
		echo '<div class="wvm-test-gateway"><p>' . esc_html( $this->description ) . '</p>';
		$options = array(
			'approve' => 'Approve — payment succeeds instantly',
			'pending' => 'Pending — succeeds after ~15 seconds (mobile-money style)',
			'decline' => 'Decline — payment fails',
		);
		foreach ( $options as $value => $label ) {
			printf(
				'<label class="wvm-test-gateway__opt"><input type="radio" name="wvm_test_outcome" value="%1$s" %2$s> %3$s</label>',
				esc_attr( $value ),
				checked( 'approve', $value, false ),
				esc_html( $label )
			);
		}
		echo '<label class="wvm-test-gateway__opt"><input type="checkbox" name="wvm_test_save" value="1"> Save this test card for automatic renewals</label></div>';
	}

	/** @return array<string,string> */
	public function process_payment( $order_id ): array {
		$order   = wc_get_order( $order_id );
		$outcome = isset( $_POST['wvm_test_outcome'] ) ? sanitize_key( wp_unslash( $_POST['wvm_test_outcome'] ) ) : 'approve'; // phpcs:ignore WordPress.Security.NonceVerification
		$save    = ! empty( $_POST['wvm_test_save'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}
		if ( $save && $order->get_customer_id() ) {
			$token = new \WC_Payment_Token_CC();
			$token->set_token( 'tok_test_' . wp_generate_password( 12, false ) );
			$token->set_gateway_id( $this->id );
			$token->set_card_type( 'visa' );
			$token->set_last4( '4242' );
			$token->set_expiry_month( '12' );
			$token->set_expiry_year( (string) ( (int) gmdate( 'Y' ) + 3 ) );
			$token->set_user_id( $order->get_customer_id() );
			$token->save();
		}
		if ( 'decline' === $outcome ) {
			$order->update_status( 'failed', 'Test gateway: declined.' );
			wc_add_notice( 'Payment declined (test). Try again or choose another option.', 'error' );
			return array( 'result' => 'failure' );
		}
		if ( 'pending' === $outcome ) {
			$order->update_status( 'on-hold', 'Test gateway: awaiting async confirmation.' );
			wvm( Scheduler::class )->at( time() + 15, Scheduler::TEST_GATEWAY, array( (int) $order_id ) );
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
		$order->payment_complete( 'TEST-' . strtoupper( wp_generate_password( 10, false ) ) );
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	public static function completeAsync( int $orderId ): void {
		$order = wc_get_order( $orderId );
		if ( $order && ! $order->is_paid() ) {
			$order->set_payment_method( 'wvm_test' );
			$order->payment_complete( 'TEST-ASYNC-' . strtoupper( wp_generate_password( 8, false ) ) );
		}
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ): bool {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->add_order_note( sprintf( 'Test gateway refund of %s: %s', (string) $amount, $reason ) );
		}
		return true;
	}
}
