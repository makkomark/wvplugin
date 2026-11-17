<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Payment;

/**
 * Sandbox adapter for the wvm_test gateway. Tokens containing "decline" fail.
 */
final class TestAutoChargeAdapter implements AutoChargeAdapter {

	public function charge( \WC_Order $order, string $token ): array {
		if ( str_contains( $token, 'decline' ) ) {
			$order->add_order_note( 'Test auto-charge declined.' );
			return array(
				'ok'      => false,
				'message' => 'Card declined (test).',
			);
		}
		$order->set_payment_method( 'wvm_test' );
		$order->set_payment_method_title( 'Test gateway (auto-charge)' );
		$order->save();
		$order->payment_complete( 'TEST-AC-' . strtoupper( wp_generate_password( 10, false ) ) );
		return array(
			'ok'      => true,
			'message' => 'Charged (test).',
		);
	}
}
