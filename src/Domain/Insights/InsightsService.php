<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Insights;

use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Money;
use WitsValley\Members\Support\Settings;

/**
 * Read-only business metrics, cached 10 minutes.
 */
final class InsightsService {

	public const METRICS = array( 'mrr', 'active_by_plan', 'new_vs_churned', 'churn_rate', 'renewal_success', 'revenue_by_gateway', 'revenue_by_country', 'free_to_paid', 'cancellation_reasons', 'manual_renewal_exposure', 'revenue_by_month' );

	public function __construct( private Settings $settings ) {}

	/**
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	private function cached( string $key, callable $fn ): mixed {
		$k   = 'wvm_ins_' . md5( $key );
		$hit = get_transient( $k );
		if ( false !== $hit ) {
			return $hit;
		}
		$value = $fn();
		set_transient( $k, $value, 10 * MINUTE_IN_SECONDS );
		return $value;
	}

	/** @return array<string,mixed> */
	public function summary( ?string $currency = null ): array {
		$currency = $this->currency( $currency );
		return $this->cached(
			'summary' . $currency,
			function () use ( $currency ): array {
				global $wpdb;
				$s       = Db::table( 'subscriptions' );
				$o       = Db::table( 'orders' );
				$p       = Db::table( 'plans' );
				$m       = Db::table( 'members' );
				$month   = gmdate( 'Y-m-01 00:00:00' );
				$active  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT s.member_id) FROM {$s} s INNER JOIN {$p} p ON p.id = s.plan_id WHERE s.status IN ('active','past_due') AND p.is_free = 0" ); // phpcs:ignore WordPress.DB
				$free    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT s.member_id) FROM {$s} s INNER JOIN {$p} p ON p.id = s.plan_id WHERE s.status = 'active' AND p.is_free = 1" ); // phpcs:ignore WordPress.DB
				$revenue = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$o} WHERE status = 'paid' AND currency = %s AND paid_at >= %s", $currency, $month ) ); // phpcs:ignore WordPress.DB
				$past    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$s} WHERE status = 'past_due'" ); // phpcs:ignore WordPress.DB
				$review  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$o} WHERE status = 'needs_review'" ); // phpcs:ignore WordPress.DB
				$new     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} WHERE created_at >= %s", $month ) ); // phpcs:ignore WordPress.DB
				$mrr     = $this->mrr( $currency );
				return array(
					'currency'              => $currency,
					'mrr_minor'             => $mrr['mrr_minor'],
					'mrr_display'           => $mrr['mrr_display'],
					'active_paid'           => $active,
					'active_free'           => $free,
					'revenue_month_minor'   => $revenue,
					'revenue_month_display' => Money::format( $revenue, $currency ),
					'past_due'              => $past,
					'needs_review'          => $review,
					'new_members_month'     => $new,
					'churn_rate'            => $this->churnRate( gmdate( 'Y-m' ) )['rate'],
					'renewal_success'       => $this->renewalSuccess( gmdate( 'Y-m' ) )['rate'],
				);
			}
		);
	}

	/** @return array<string,mixed> */
	public function mrr( ?string $currency = null ): array {
		$currency = $this->currency( $currency );
		global $wpdb;
		$s    = Db::table( 'subscriptions' );
		$p    = Db::table( 'plans' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.interval_unit, p.interval_count, s.amount_minor FROM {$s} s INNER JOIN {$p} p ON p.id = s.plan_id WHERE s.status IN ('active','past_due') AND p.is_free = 0 AND s.currency = %s AND s.organisation_id IS NULL", $currency ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$mrr  = 0.0;
		foreach ( $rows ?: array() as $r ) {
			$months = match ( $r['interval_unit'] ) {
				'monthly'   => 1,
				'quarterly' => 3,
				'annual'    => 12,
				default     => 0,
			};
			if ( $months > 0 ) {
				$mrr += (int) $r['amount_minor'] / ( $months * max( 1, (int) $r['interval_count'] ) );
			}
		}
		return array(
			'currency'    => $currency,
			'mrr_minor'   => (int) round( $mrr ),
			'mrr_display' => Money::format( (int) round( $mrr ), $currency ),
		);
	}

	/** @return list<array<string,mixed>> */
	public function activeByPlan(): array {
		global $wpdb;
		$s = Db::table( 'subscriptions' );
		$p = Db::table( 'plans' );
		return array_map(
			static fn( $r ) => array(
				'plan'  => $r['name'],
				'slug'  => $r['slug'],
				'count' => (int) $r['c'],
			),
			$wpdb->get_results( "SELECT p.name, p.slug, COUNT(DISTINCT s.member_id) c FROM {$p} p LEFT JOIN {$s} s ON s.plan_id = p.id AND s.status IN ('active','past_due') WHERE p.archived_at IS NULL GROUP BY p.id ORDER BY p.sort_order", ARRAY_A ) ?: array() // phpcs:ignore WordPress.DB
		);
	}

	/** @return list<array<string,mixed>> */
	public function newVsChurned( int $months = 6 ): array {
		global $wpdb;
		$s   = Db::table( 'subscriptions' );
		$p   = Db::table( 'plans' );
		$e   = Db::table( 'subscription_events' );
		$out = array();
		for ( $i = $months - 1; $i >= 0; $i-- ) {
			$start = gmdate( 'Y-m-01 00:00:00', strtotime( "-{$i} months", strtotime( gmdate( 'Y-m-15' ) ) ) );
			$end   = gmdate( 'Y-m-t 23:59:59', strtotime( $start ) );
			$new   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$s} s INNER JOIN {$p} p ON p.id = s.plan_id WHERE p.is_free = 0 AND s.started_at BETWEEN %s AND %s", $start, $end ) ); // phpcs:ignore WordPress.DB
			$churn = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT e.subscription_id) FROM {$e} e INNER JOIN {$s} s ON s.id = e.subscription_id INNER JOIN {$p} p ON p.id = s.plan_id WHERE p.is_free = 0 AND e.to_status IN ('expired','cancelled') AND e.from_status <> 'pending' AND (e.reason IS NULL OR e.reason NOT LIKE %s) AND e.created_at BETWEEN %s AND %s", 'Superseded%', $start, $end ) ); // phpcs:ignore WordPress.DB
			$out[] = array(
				'month'   => substr( $start, 0, 7 ),
				'new'     => $new,
				'churned' => $churn,
			);
		}
		return $out;
	}

	/** @return array{month:string, rate:float, churned:int, base:int} */
	public function churnRate( string $month ): array {
		$row = null;
		foreach ( $this->newVsChurned( 1 ) as $r ) {
			$row = $r;
		}
		global $wpdb;
		$s     = Db::table( 'subscriptions' );
		$p     = Db::table( 'plans' );
		$start = $month . '-01 00:00:00';
		$base  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$s} s INNER JOIN {$p} p ON p.id = s.plan_id WHERE p.is_free = 0 AND s.started_at < %s AND (s.cancelled_at IS NULL OR s.cancelled_at >= %s) AND s.status <> 'pending'", $start, $start ) ); // phpcs:ignore WordPress.DB
		$churn = $row ? (int) $row['churned'] : 0;
		return array(
			'month'   => $month,
			'rate'    => $base ? round( 100 * $churn / $base, 1 ) : 0.0,
			'churned' => $churn,
			'base'    => $base,
		);
	}

	/** @return array<string,mixed> */
	public function renewalSuccess( string $month, bool $byMethod = false ): array {
		global $wpdb;
		$o     = Db::table( 'orders' );
		$py    = Db::table( 'payments' );
		$start = $month . '-01 00:00:00';
		$end   = gmdate( 'Y-m-t 23:59:59', strtotime( $start ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$o} WHERE type IN ('renewal','reactivation') AND created_at BETWEEN %s AND %s", $start, $end ) ); // phpcs:ignore WordPress.DB
		$paid  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$o} WHERE type IN ('renewal','reactivation') AND status = 'paid' AND created_at BETWEEN %s AND %s", $start, $end ) ); // phpcs:ignore WordPress.DB
		$out   = array(
			'month' => $month,
			'rate'  => $total ? round( 100 * $paid / $total, 1 ) : 0.0,
			'paid'  => $paid,
			'total' => $total,
		);
		if ( $byMethod ) {
			$out['by_method'] = array_map(
				static fn( $r ) => array(
					'gateway' => (string) $r['gateway'],
					'paid'    => (int) $r['paid'],
				),
				(array) $wpdb->get_results( $wpdb->prepare( "SELECT py.gateway, COUNT(*) paid FROM {$o} o INNER JOIN {$py} py ON py.order_id = o.id WHERE o.type IN ('renewal','reactivation') AND o.status = 'paid' AND o.created_at BETWEEN %s AND %s GROUP BY py.gateway", $start, $end ), ARRAY_A ) // phpcs:ignore WordPress.DB
			);
		}
		return $out;
	}

	/** @return list<array<string,mixed>> */
	public function revenueByGateway( string $from, string $to, ?string $currency = null ): array {
		global $wpdb;
		$o        = Db::table( 'orders' );
		$py       = Db::table( 'payments' );
		$currency = $this->currency( $currency );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(py.gateway,'manual') gateway, SUM(o.amount_minor) total, COUNT(*) n FROM {$o} o LEFT JOIN {$py} py ON py.order_id = o.id WHERE o.status = 'paid' AND o.currency = %s AND o.paid_at BETWEEN %s AND %s GROUP BY py.gateway ORDER BY total DESC", $currency, $from . ' 00:00:00', $to . ' 23:59:59' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map(
			static fn( $r ) => array(
				'gateway'       => $r['gateway'],
				'orders'        => (int) $r['n'],
				'total_minor'   => (int) $r['total'],
				'total_display' => Money::format( (int) $r['total'], $currency ),
			),
			$rows ?: array()
		);
	}

	/** @return list<array<string,mixed>> */
	public function revenueByCountry( string $from, string $to, ?string $currency = null ): array {
		global $wpdb;
		$o        = Db::table( 'orders' );
		$m        = Db::table( 'members' );
		$currency = $this->currency( $currency );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(m.country,'—') country, SUM(o.amount_minor) total, COUNT(*) n FROM {$o} o INNER JOIN {$m} m ON m.id = o.member_id WHERE o.status = 'paid' AND o.currency = %s AND o.paid_at BETWEEN %s AND %s GROUP BY m.country ORDER BY total DESC", $currency, $from . ' 00:00:00', $to . ' 23:59:59' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map(
			static fn( $r ) => array(
				'country'       => $r['country'],
				'orders'        => (int) $r['n'],
				'total_minor'   => (int) $r['total'],
				'total_display' => Money::format( (int) $r['total'], $currency ),
			),
			$rows ?: array()
		);
	}

	/** @return list<array<string,mixed>> */
	public function revenueByMonth( int $months = 12, ?string $currency = null ): array {
		global $wpdb;
		$o        = Db::table( 'orders' );
		$currency = $this->currency( $currency );
		$out      = array();
		for ( $i = $months - 1; $i >= 0; $i-- ) {
			$start = gmdate( 'Y-m-01 00:00:00', strtotime( "-{$i} months", strtotime( gmdate( 'Y-m-15' ) ) ) );
			$end   = gmdate( 'Y-m-t 23:59:59', strtotime( $start ) );
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$o} WHERE status = 'paid' AND currency = %s AND paid_at BETWEEN %s AND %s", $currency, $start, $end ) ); // phpcs:ignore WordPress.DB
			$out[] = array(
				'month'         => substr( $start, 0, 7 ),
				'total_minor'   => $total,
				'total_display' => Money::format( $total, $currency ),
			);
		}
		return $out;
	}

	/** @return list<array<string,mixed>> */
	public function freeToPaidConversion( int $months = 6 ): array {
		global $wpdb;
		$s   = Db::table( 'subscriptions' );
		$p   = Db::table( 'plans' );
		$out = array();
		for ( $i = $months - 1; $i >= 0; $i-- ) {
			$start = gmdate( 'Y-m-01 00:00:00', strtotime( "-{$i} months", strtotime( gmdate( 'Y-m-15' ) ) ) );
			$end   = gmdate( 'Y-m-t 23:59:59', strtotime( $start ) );
			$free  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT s.member_id) FROM {$s} s INNER JOIN {$p} p ON p.id = s.plan_id WHERE p.is_free = 1 AND s.started_at BETWEEN %s AND %s", $start, $end ) ); // phpcs:ignore WordPress.DB
			$conv  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT s.member_id) FROM {$s} s INNER JOIN {$p} p ON p.id = s.plan_id WHERE p.is_free = 1 AND s.started_at BETWEEN %s AND %s AND EXISTS (SELECT 1 FROM {$s} s2 INNER JOIN {$p} p2 ON p2.id = s2.plan_id WHERE s2.member_id = s.member_id AND p2.is_free = 0 AND s2.started_at >= s.started_at)", $start, $end ) ); // phpcs:ignore WordPress.DB
			$out[] = array(
				'month'        => substr( $start, 0, 7 ),
				'free_signups' => $free,
				'converted'    => $conv,
				'rate'         => $free ? round( 100 * $conv / $free, 1 ) : 0.0,
			);
		}
		return $out;
	}

	/** @return list<array<string,mixed>> */
	public function cancellationReasons( string $from, string $to ): array {
		global $wpdb;
		$s = Db::table( 'subscriptions' );
		return array_map(
			static fn( $r ) => array(
				'reason' => $r['cancel_reason'] ?: 'Not given',
				'count'  => (int) $r['c'],
			),
			$wpdb->get_results( $wpdb->prepare( "SELECT cancel_reason, COUNT(*) c FROM {$s} WHERE cancel_reason IS NOT NULL AND cancel_reason <> 'superseded' AND updated_at BETWEEN %s AND %s GROUP BY cancel_reason ORDER BY c DESC LIMIT 20", $from . ' 00:00:00', $to . ' 23:59:59' ), ARRAY_A ) ?: array() // phpcs:ignore WordPress.DB
		);
	}

	/** @return array<string,mixed> Manual renewals due next 7 days (no saved method). */
	public function manualRenewalExposure( int $days = 7 ): array {
		global $wpdb;
		$s    = Db::table( 'subscriptions' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT currency, COUNT(*) n, SUM(amount_minor) total FROM {$s} WHERE status = 'active' AND cancel_at_period_end = 0 AND payment_method_id IS NULL AND organisation_id IS NULL AND current_period_end BETWEEN %s AND %s GROUP BY currency", Clock::mysql(), Clock::mysql( Clock::now()->modify( "+{$days} days" ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'days'  => $days,
			'items' => array_map(
				static fn( $r ) => array(
					'currency'      => $r['currency'],
					'count'         => (int) $r['n'],
					'total_minor'   => (int) $r['total'],
					'total_display' => Money::format( (int) $r['total'], (string) $r['currency'] ),
				),
				$rows ?: array()
			),
		);
	}

	/**
	 * Dispatch by metric name (REST /insights/{metric}).
	 *
	 * @param array<string,mixed> $q from,to,currency,months
	 */
	public function metric( string $metric, array $q ): mixed {
		$from     = (string) ( $q['from'] ?? gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$to       = (string) ( $q['to'] ?? gmdate( 'Y-m-d' ) );
		$currency = isset( $q['currency'] ) ? (string) $q['currency'] : null;
		$months   = max( 1, min( 24, (int) ( $q['months'] ?? 6 ) ) );
		return $this->cached(
			$metric . wp_json_encode( array( $from, $to, $currency, $months ) ),
			fn() => match ( $metric ) {
				'mrr'                     => $this->mrr( $currency ),
				'active_by_plan'          => $this->activeByPlan(),
				'new_vs_churned'          => $this->newVsChurned( $months ),
				'churn_rate'              => $this->churnRate( substr( $to, 0, 7 ) ),
				'renewal_success'         => $this->renewalSuccess( substr( $to, 0, 7 ), true ),
				'revenue_by_gateway'      => $this->revenueByGateway( $from, $to, $currency ),
				'revenue_by_country'      => $this->revenueByCountry( $from, $to, $currency ),
				'revenue_by_month'        => $this->revenueByMonth( $months, $currency ),
				'free_to_paid'            => $this->freeToPaidConversion( $months ),
				'cancellation_reasons'    => $this->cancellationReasons( $from, $to ),
				'manual_renewal_exposure' => $this->manualRenewalExposure( 7 ),
				default                   => throw DomainError::notFound( 'metric' ),
			}
		);
	}

	/** Flatten a metric to CSV. */
	public function csv( string $metric, array $q ): string {
		$data = $this->metric( $metric, $q );
		$rows = isset( $data['items'] ) ? $data['items'] : ( array_is_list( (array) $data ) ? (array) $data : array( (array) $data ) );
		$fh   = fopen( 'php://temp', 'w+' );
		if ( ! $fh ) {
			return '';
		}
		if ( $rows ) {
			fputcsv( $fh, array_keys( (array) $rows[0] ) );
			foreach ( $rows as $r ) {
				fputcsv( $fh, array_map( static fn( $v ) => is_scalar( $v ) ? $v : wp_json_encode( $v ), (array) $r ) );
			}
		}
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}

	private function currency( ?string $currency ): string {
		if ( $currency && preg_match( '/^[A-Za-z]{3}$/', $currency ) ) {
			return strtoupper( $currency );
		}
		$enabled = (array) $this->settings->get( 'payments', 'currencies_enabled', array( 'UGX' ) );
		return strtoupper( (string) ( $enabled[0] ?? 'UGX' ) );
	}
}
