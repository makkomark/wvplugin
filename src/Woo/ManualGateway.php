<?php
declare( strict_types=1 );

namespace WitsValley\Members\Woo;

/**
 * Offline payments recorded by staff (bank transfer, cash, mobile money outside a gateway). Never shown at checkout.
 */
final class ManualGateway extends \WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'wvm_manual';
		$this->method_title       = 'WitsValley manual payment';
		$this->method_description = 'Used when staff record an offline payment from the WitsValley › Payments screen.';
		$this->title              = 'Manual payment';
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );
		$this->enabled            = 'yes';
		$this->form_fields        = array();
	}

	public function is_available(): bool {
		return false;
	}

	public function process_payment( $order_id ): array {
		return array( 'result' => 'failure' );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ): bool {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->add_order_note( sprintf( 'Manual refund of %s recorded (pay the member back offline): %s', (string) $amount, $reason ) );
		}
		return true;
	}
}
