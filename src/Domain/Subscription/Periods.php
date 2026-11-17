<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Subscription;

use DateTimeImmutable;

/**
 * Billing period arithmetic.
 */
final class Periods {

	/**
	 * End of the period starting at $start for a plan (null = no end).
	 *
	 * @param array<string,mixed> $plan Presented plan.
	 */
	public static function next( DateTimeImmutable $start, array $plan, int $extraDays = 0 ): ?DateTimeImmutable {
		$count = max( 1, (int) ( $plan['interval_count'] ?? 1 ) );
		$end   = match ( (string) ( $plan['interval'] ?? 'monthly' ) ) {
			'monthly'   => self::addMonths( $start, $count ),
			'quarterly' => self::addMonths( $start, 3 * $count ),
			'annual'    => self::addMonths( $start, 12 * $count ),
			'custom'    => $start->modify( '+' . wvm( \WitsValley\Members\Support\Settings::class )->lifecycleSeconds( $count ) . ' seconds' ),
			default     => null,
		};
		if ( $end && $extraDays > 0 ) {
			$end = $end->modify( '+' . $extraDays . ' days' );
		}
		return $end;
	}

	/** Month arithmetic that clamps to month end (Jan 31 + 1 month = Feb 28/29). */
	public static function addMonths( DateTimeImmutable $start, int $months ): DateTimeImmutable {
		$day    = (int) $start->format( 'j' );
		$first  = $start->modify( 'first day of this month' )->modify( '+' . $months . ' months' );
		$last   = (int) $first->format( 't' );
		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), min( $day, $last ) );
	}

	public static function isRecurring( array $plan ): bool {
		return in_array( (string) ( $plan['interval'] ?? '' ), array( 'monthly', 'quarterly', 'annual', 'custom' ), true );
	}
}
