<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Plan;

use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\Repository;

final class PlanPriceRepository extends Repository {

	protected string $table = 'plan_prices';

	protected array $casts = array( 'amount_minor' => 'int' );

	/** @return array<string,int> currency => amount_minor */
	public function forPlan( int $planId ): array {
		$out = array();
		foreach ( $this->where( array( 'plan_id' => $planId ), 'currency ASC' ) as $row ) {
			$out[ (string) $row['currency'] ] = (int) $row['amount_minor'];
		}
		return $out;
	}

	public function price( int $planId, string $currency ): ?int {
		$row = $this->first(
			array(
				'plan_id'  => $planId,
				'currency' => strtoupper( $currency ),
			)
		);
		return $row ? (int) $row['amount_minor'] : null;
	}

	public function upsert( int $planId, string $currency, int $amountMinor ): void {
		global $wpdb;
		$now   = Clock::mysql();
		$table = Db::table( 'plan_prices' );
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"INSERT INTO {$table} (plan_id, currency, amount_minor, created_at, updated_at) VALUES (%d, %s, %d, %s, %s)
				ON DUPLICATE KEY UPDATE amount_minor = VALUES(amount_minor), updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB
				$planId,
				strtoupper( $currency ),
				$amountMinor,
				$now,
				$now
			)
		);
	}

	public function remove( int $planId, string $currency ): void {
		global $wpdb;
		$wpdb->delete(
			Db::table( 'plan_prices' ),
			array(
				'plan_id'  => $planId,
				'currency' => strtoupper( $currency ),
			)
		); // phpcs:ignore WordPress.DB
	}
}
