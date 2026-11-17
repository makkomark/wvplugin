<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Payment;

/**
 * A gateway that can charge a saved token without the member present.
 * Register via the `wvm_autocharge_adapters` filter: [ 'gateway_id' => AdapterInstance ].
 */
interface AutoChargeAdapter {

	/**
	 * Attempt to charge the Woo order with the stored token.
	 * On success the adapter MUST call $order->payment_complete( $transactionId ).
	 *
	 * @return array{ok:bool, message:string}
	 */
	public function charge( \WC_Order $order, string $token ): array;
}
