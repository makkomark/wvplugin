<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Audit\AuditService;
use WitsValley\Members\Domain\Insights\InsightsService;
use WitsValley\Members\Domain\Kit\KitSync;
use WitsValley\Members\Domain\Tools\ToolsService;
use WitsValley\Members\Domain\Workshop\WorkshopService;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\Money;
use WitsValley\Members\Support\Pagination;

/**
 * Admin Home: needs attention, today, quick stats, recent activity.
 */
final class HomeController extends Controller {

	public function routes(): void {
		$this->route( 'GET', '/admin/home', fn() => $this->home(), 'wvm_access' );
	}

	/** @return array<string,mixed> */
	private function home(): array {
		global $wpdb;
		$o     = Db::table( 'orders' );
		$s     = Db::table( 'subscriptions' );
		$m     = Db::table( 'members' );
		$c     = Db::table( 'consultation_requests' );
		$a     = Db::table( 'applications' );
		$n     = Db::table( 'notifications' );
		$today = gmdate( 'Y-m-d 00:00:00' );
		$end   = gmdate( 'Y-m-d 23:59:59' );

		$attention = array(
			array(
			'key'   => 'review',
			'label' => 'Payments needing review',
			'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$o} WHERE status = 'needs_review'" ),
			'route' => '/payments/review',
			'cap'   => 'wvm_view_payments',
			'tone'  => 'danger',
			), // phpcs:ignore WordPress.DB
			array(
		'key'   => 'past_due',
		'label' => 'Members past due',
		'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$s} WHERE status = 'past_due'" ),
		'route' => '/members?status=past_due',
		'cap'   => 'wvm_view_members',
		'tone'  => 'warning',
		), // phpcs:ignore WordPress.DB
			array(
		'key'   => 'consultations',
		'label' => 'New consultation requests',
		'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c} WHERE status = 'new'" ),
		'route' => '/consultations',
		'cap'   => 'wvm_manage_workshops',
		'tone'  => 'info',
		), // phpcs:ignore WordPress.DB
			array(
		'key'   => 'applications',
		'label' => 'New Enterprise applications',
		'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$a} WHERE status = 'new'" ),
		'route' => '/enterprise/applications',
		'cap'   => 'wvm_manage_enterprise',
		'tone'  => 'info',
		), // phpcs:ignore WordPress.DB
			array(
		'key'   => 'kit',
		'label' => 'Kit sync errors (24h)',
		'count' => wvm( KitSync::class )->errorCount(),
		'route' => '/settings/kit',
		'cap'   => 'wvm_manage_settings',
		'tone'  => 'warning',
		),
			array(
		'key'   => 'email',
		'label' => 'Failed emails (24h)',
		'count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$n} WHERE status = 'failed' AND created_at >= %s", Clock::mysql( Clock::now()->modify( '-1 day' ) ) ) ),
		'route' => '/settings/notifications',
		'cap'   => 'wvm_manage_settings',
		'tone'  => 'warning',
		), // phpcs:ignore WordPress.DB
		);
		$attention = array_values( array_filter( $attention, static fn( $i ) => current_user_can( $i['cap'] ) ) );

		$paid_today = $wpdb->get_results( $wpdb->prepare( "SELECT currency, SUM(amount_minor) total, COUNT(*) n FROM {$o} WHERE status = 'paid' AND paid_at BETWEEN %s AND %s GROUP BY currency", $today, $end ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$workshops  = wvm( WorkshopService::class )->list(
			array(
				'from' => gmdate( 'Y-m-d' ),
				'to'   => gmdate( 'Y-m-d' ),
			),
			new Pagination( 1, 10 )
		)['items'];

		$out = array(
			'attention' => $attention,
			'today'     => array(
				'new_members'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} WHERE created_at BETWEEN %s AND %s", $today, $end ) ), // phpcs:ignore WordPress.DB
				'payments'     => array_map(
					static fn( $r ) => array(
					'currency'      => $r['currency'],
					'count'         => (int) $r['n'],
					'total_display' => Money::format( (int) $r['total'], (string) $r['currency'] ),
					),
					$paid_today ?: array()
				),
				'renewals_due' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$s} WHERE status = 'active' AND current_period_end BETWEEN %s AND %s", $today, $end ) ), // phpcs:ignore WordPress.DB
				'workshops'    => $workshops,
			),
			'health'    => wvm( ToolsService::class )->health(),
			'stats'     => current_user_can( 'wvm_view_insights' ) ? wvm( InsightsService::class )->summary() : null,
			'activity'  => current_user_can( 'wvm_view_audit' ) ? wvm( AuditService::class )->recent( 12 ) : array(),
		);
		return $out;
	}
}
