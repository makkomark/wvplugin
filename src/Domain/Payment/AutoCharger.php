<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Payment;

/**
 * Auto-charge via registered gateway adapters (only where a gateway exposes saved tokens).
 */
final class AutoCharger {

	public function __construct( private PaymentMethodRepository $methods ) {}

	/** @return array<string,AutoChargeAdapter> */
	public function adapters(): array {
		$adapters = array( 'wvm_test' => new TestAutoChargeAdapter() );
		return (array) apply_filters( 'wvm_autocharge_adapters', $adapters );
	}

	/** Can this subscription be auto-charged? */
	public function canCharge( array $sub ): bool {
		if ( empty( $sub['payment_method_id'] ) ) {
			return false;
		}
		$method = $this->methods->find( (int) $sub['payment_method_id'] );
		return $method && isset( $this->adapters()[ (string) $method['gateway'] ] );
	}

	/**
	 * @param array<string,mixed> $sub   Raw subscription.
	 * @param array<string,mixed> $order Presented plugin order with woo_order_id.
	 * @return array{ok:bool, message:string}
	 */
	public function charge( array $sub, array $order ): array {
		$method = $sub['payment_method_id'] ? $this->methods->find( (int) $sub['payment_method_id'] ) : null;
		if ( ! $method ) {
			return array( 'ok' => false, 'message' => 'No saved payment method.' );
		}
		$adapter = $this->adapters()[ (string) $method['gateway'] ] ?? null;
		$wc      = wc_get_order( (int) $order['woo_order_id'] );
		if ( ! $adapter || ! $wc ) {
			return array( 'ok' => false, 'message' => 'Gateway does not support automatic charging.' );
		}
		try {
			return $adapter->charge( $wc, (string) $method['token'] );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'message' => $e->getMessage() );
		}
	}
}
