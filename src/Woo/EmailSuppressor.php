<?php
declare( strict_types=1 );

namespace WitsValley\Members\Woo;

use WitsValley\Members\Domain\Order\OrderService;

/**
 * Plugin orders send WitsValley-branded emails instead of WooCommerce customer emails.
 */
final class EmailSuppressor {

	public const CUSTOMER_EMAILS = array(
		'customer_processing_order',
		'customer_completed_order',
		'customer_on_hold_order',
		'customer_invoice',
		'customer_note',
		'customer_refunded_order',
		'customer_partially_refunded_order',
		'customer_failed_order',
		'customer_pending_order',
		'customer_cancelled_order',
	);

	public function register(): void {
		foreach ( self::CUSTOMER_EMAILS as $id ) {
			add_filter( 'woocommerce_email_enabled_' . $id, array( $this, 'maybeDisable' ), 99, 2 );
		}
	}

	/** @param mixed $object */
	public function maybeDisable( $enabled, $object = null ) {
		if ( $object instanceof \WC_Order && $object->get_meta( OrderService::META ) ) {
			return false;
		}
		return $enabled;
	}
}
