<?php
namespace WitsValley\Members\Tests\Integration;

use WitsValley\Members\Domain\Access\LevelResolver;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Subscription\Dunning;
use WitsValley\Members\Domain\Subscription\EventRepository;
use WitsValley\Members\Domain\Subscription\SubscriptionService;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Tests\TestCase;

/** Acceptance #3 and #4: full dunning cycle, anchored renewals, restore, cancel/resume. */
final class SubscriptionLifecycleTest extends TestCase {

	public function test_late_renewal_extends_from_previous_period_end(): void {
		$p   = $this->paidMember( 'basic' );
		$sub = $p['sub'];
		$end = Clock::parse( $sub['current_period_end'] );

		Clock::freeze( $end->modify( '+1 hour' ) );
		wvm( Dunning::class )->renewalDue( (int) $sub['id'] );
		$this->assertSame( 'past_due', $this->sub( (int) $sub['id'] )['status'] );

		Clock::freeze( $end->modify( '+3 days' ) );
		$order = wvm( OrderService::class )->ensureRenewalOrder( $this->sub( (int) $sub['id'] ) );
		$this->assertSame( 'renewal', $order['type'] );
		$this->payWoo( (int) $order['woo_order_id'] );

		$after = $this->sub( (int) $sub['id'] );
		$this->assertSame( 'active', $after['status'] );
		$this->assertSame( Clock::mysql( $end ), $after['current_period_start'], 'New period starts at the previous end, not at payment time.' );
		$this->assertSame( \WitsValley\Members\Domain\Subscription\Periods::addMonths( $end, 1 )->format( 'Y-m-d H:i:s' ), $after['current_period_end'] );
	}

	public function test_full_dunning_cycle_and_restore(): void {
		$p   = $this->paidMember( 'premium' );
		$id  = (int) $p['sub']['id'];
		$end = Clock::parse( $p['sub']['current_period_end'] );
		$uid = (int) $p['member']['user_id'];
		$this->assertSame( 2, wvm( LevelResolver::class )->levelFor( $uid ) );

		Clock::freeze( $end->modify( '+1 minute' ) );
		wvm( Dunning::class )->renewalDue( $id );
		$this->assertSame( 'past_due', $this->sub( $id )['status'] );
		wvm( LevelResolver::class )->flush();
		$this->assertSame( 2, wvm( LevelResolver::class )->levelFor( $uid ), 'Access continues during grace.' );

		Clock::freeze( $end->modify( '+8 days' ) );
		wvm( Dunning::class )->endGrace( $id );
		$this->assertSame( 'suspended', $this->sub( $id )['status'] );
		wvm( LevelResolver::class )->flush();
		$this->assertSame( 0, wvm( LevelResolver::class )->levelFor( $uid ) );

		Clock::freeze( $end->modify( '+40 days' ) );
		wvm( Dunning::class )->expire( $id );
		$this->assertSame( 'expired', $this->sub( $id )['status'] );

		// Within the rejoin window a paid reactivation restores the same subscription.
		$order = wvm( OrderService::class )->ensureRenewalOrder( $this->sub( $id ) );
		$this->assertSame( 'reactivation', $order['type'] );
		$this->payWoo( (int) $order['woo_order_id'] );
		$this->assertSame( 'active', $this->sub( $id )['status'] );
		wvm( LevelResolver::class )->flush();
		$this->assertSame( 2, wvm( LevelResolver::class )->levelFor( $uid ) );

		$events = array_map( static fn( $e ) => $e['to_status'], wvm( EventRepository::class )->where( array( 'subscription_id' => $id ), 'id ASC' ) );
		foreach ( array( 'pending', 'active', 'past_due', 'suspended', 'expired' ) as $s ) {
			$this->assertContains( $s, $events );
		}
	}

	public function test_cancel_at_period_end_with_resume(): void {
		$p   = $this->paidMember( 'basic' );
		$id  = (int) $p['sub']['id'];
		$svc = wvm( SubscriptionService::class );
		$svc->cancel( $this->sub( $id ), 'Too expensive' );
		$this->assertSame( 'active', $this->sub( $id )['status'] );
		$this->assertTrue( $this->sub( $id )['cancel_at_period_end'] );
		$svc->resume( $this->sub( $id ) );
		$this->assertFalse( $this->sub( $id )['cancel_at_period_end'] );

		$svc->cancel( $this->sub( $id ), 'Not using it' );
		Clock::freeze( Clock::parse( $this->sub( $id )['current_period_end'] )->modify( '+1 minute' ) );
		wvm( Dunning::class )->renewalDue( $id );
		$this->assertSame( 'cancelled', $this->sub( $id )['status'] );

		$this->expectException( DomainError::class );
		$svc->transition( $this->sub( $id ), 'active', 'illegal' );
	}

	public function test_prorated_upgrade_and_downgrade_at_renewal(): void {
		$p   = $this->paidMember( 'basic' );
		$id  = (int) $p['sub']['id'];
		$svc = wvm( SubscriptionService::class );
		$start = Clock::parse( $p['sub']['current_period_start'] );
		$end   = Clock::parse( $p['sub']['current_period_end'] );
		$days  = (int) round( ( $end->getTimestamp() - $start->getTimestamp() ) / DAY_IN_SECONDS );
		Clock::freeze( $start->modify( '+10 days' ) );
		$preview  = $svc->upgradePreview( $this->sub( $id ), (int) $this->plan( 'premium' )['id'] );
		$expected = (int) round( ( 50000 - 20000 ) * ( $days - 10 ) / $days );
		$this->assertSame( 'prorated', $preview['mode'] );
		$this->assertEqualsWithDelta( $expected, $preview['amount_minor'], 1000 );

		$res = $svc->changePlan( $this->sub( $id ), (int) $this->plan( 'premium' )['id'], 'immediate' );
		$this->assertSame( 'upgrade', $res['order']['type'] );
		$this->payWoo( (int) $res['order']['woo_order_id'] );
		$this->assertSame( (int) $this->plan( 'premium' )['id'], (int) $this->sub( $id )['plan_id'] );
		$this->assertSame( Clock::mysql( $end ), $this->sub( $id )['current_period_end'], 'Upgrade does not move the renewal date.' );

		$svc->changePlan( $this->sub( $id ), (int) $this->plan( 'basic' )['id'], 'at_renewal' );
		$this->assertSame( (int) $this->plan( 'basic' )['id'], (int) $this->sub( $id )['pending_plan_id'] );
		Clock::freeze( $end->modify( '-1 day' ) );
		$order = wvm( OrderService::class )->ensureRenewalOrder( $this->sub( $id ) );
		$this->assertSame( 20000, $order['amount_minor'], 'Renewal is billed at the downgraded plan price.' );
		$this->payWoo( (int) $order['woo_order_id'] );
		$this->assertSame( (int) $this->plan( 'basic' )['id'], (int) $this->sub( $id )['plan_id'] );
		$this->assertNull( $this->sub( $id )['pending_plan_id'] );
	}

	public function test_admin_extend_creates_zero_amount_adjustment_and_audit(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$p      = $this->paidMember( 'basic' );
		$id     = (int) $p['sub']['id'];
		$before = Clock::parse( $p['sub']['current_period_end'] );
		wvm( SubscriptionService::class )->extend( $this->sub( $id ), 14, 'Goodwill credit' );
		$this->assertSame( Clock::mysql( $before->modify( '+14 days' ) ), $this->sub( $id )['current_period_end'] );
		global $wpdb;
		$adj = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . \WitsValley\Members\Support\Db::table( 'orders' ) . " WHERE subscription_id = %d AND type = 'manual_adjust'", $id ), ARRAY_A );
		$this->assertSame( '0', (string) $adj['amount_minor'] );
		$audit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . \WitsValley\Members\Support\Db::table( 'audit_log' ) . " WHERE action = 'subscription.extended' AND entity_id = %d", $id ), ARRAY_A );
		$this->assertSame( 'Goodwill credit', $audit['reason'] );
		$this->assertSame( 'admin', $audit['actor_type'] );
		$this->assertNotEmpty( $audit['before_data'] );
		$this->assertNotEmpty( $audit['after_data'] );
	}

	public function test_reason_is_required_for_admin_actions(): void {
		$p = $this->paidMember( 'basic' );
		$this->expectException( DomainError::class );
		wvm( SubscriptionService::class )->adminSuspend( $this->sub( (int) $p['sub']['id'] ), '' );
	}
}
