<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Order;

use WitsValley\Members\Support\Repository;

final class OrderRepository extends Repository {

	protected string $table = 'orders';

	protected array $casts = array(
		'amount_minor'   => 'int',
		'receipt_number' => 'string',
	);

	/** @return array<string,mixed>|null */
	public function byWoo( int $wooOrderId ): ?array {
		return $this->findBy( 'woo_order_id', $wooOrderId );
	}

	/** @return array<string,mixed>|null */
	public function byToken( string $token ): ?array {
		if ( ! preg_match( '/^[a-f0-9]{16,64}$/', $token ) ) {
			return null;
		}
		return $this->findBy( 'pay_link_token', $token );
	}

	/** @return array<string,mixed>|null A pending order of $type for a subscription. */
	public function pendingFor( int $subscriptionId, array $types ): ?array {
		return $this->first(
			array(
				'subscription_id' => $subscriptionId,
				'status'          => 'pending',
				'type'            => $types,
			)
		);
	}
}
