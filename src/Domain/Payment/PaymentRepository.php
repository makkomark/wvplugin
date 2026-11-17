<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Payment;

use WitsValley\Members\Support\Repository;

final class PaymentRepository extends Repository {

	protected string $table = 'payments';

	protected bool $hasUpdatedAt = false;

	protected array $casts = array(
		'amount_minor' => 'int',
		'raw_payload'  => 'encrypted',
		'provider_ref' => 'string',
	);
}
