<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Kit;

/**
 * Kit answered HTTP 429 (too many requests). The job is retried later instead of being marked as an error.
 */
final class KitRateLimited extends \RuntimeException {

	public function __construct( public readonly int $retryAfter = 60 ) {
		parent::__construct( 'Kit rate limit reached; retrying in ' . $retryAfter . ' seconds.' );
	}
}
