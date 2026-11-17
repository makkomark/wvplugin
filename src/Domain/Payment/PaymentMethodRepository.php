<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Payment;

use WitsValley\Members\Support\Repository;

final class PaymentMethodRepository extends Repository {

	protected string $table = 'payment_methods';

	protected bool $hasUpdatedAt = false;

	protected array $casts = array(
		'token'      => 'encrypted',
		'is_default' => 'bool',
	);

	/** @return array<string,mixed>|null */
	public function defaultFor( int $memberId ): ?array {
		return $this->first(
			array(
				'member_id'  => $memberId,
				'is_default' => 1,
			)
		) ?? $this->first( array( 'member_id' => $memberId ) );
	}
}
