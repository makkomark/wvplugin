<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Order;

/**
 * Internal signal: the order became paid concurrently (idempotency guard).
 */
final class AlreadyPaid extends \RuntimeException {}
