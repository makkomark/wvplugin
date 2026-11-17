<?php
namespace WitsValley\Members\Tests\Integration;

use WitsValley\Members\Domain\Order\OrderListener;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Payment\PaymentRepository;
use WitsValley\Members\Domain\Subscription\SubscriptionRepository;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Tests\TestCase;

/** Acceptance #1 and #2. */
final class OrderListenerTest extends TestCase {

	public function test_activation_only_on_paid_status_and_replay_is_noop(): void {
		$member = $this->member();
		$order  = wvm( OrderService::class )->createForPlan( (int) $member['id'], (int) $this->plan( 'basic' )['id'], 'new', 'UGX' );
		$this->assertSame( 'pending', $order['status'] );
		$this->assertSame( 'pending', wvm( SubscriptionRepository::class )->find( (int) $order['subscription_id'] )['status'] );

		// An on-hold event (not processing/completed) must not activate.
		$wc = wc_get_order( (int) $order['woo_order_id'] );
		$wc->update_status( 'on-hold' );
		$this->assertSame( 'pending', wvm( OrderService::class )->get( (int) $order['id'] )['status'] );

		$this->payWoo( (int) $order['woo_order_id'] );
		$paid = wvm( OrderService::class )->get( (int) $order['id'] );
		$this->assertSame( 'paid', $paid['status'] );
		$this->assertNotEmpty( $paid['receipt_number'] );
		$sub = wvm( SubscriptionRepository::class )->find( (int) $order['subscription_id'] );
		$this->assertSame( 'active', $sub['status'] );
		$end = $sub['current_period_end'];

		$listener = wvm( OrderListener::class );
		$this->assertSame( 'duplicate', $listener->handle( 'processing', (int) $order['woo_order_id'] ) );
		$this->assertSame( 'duplicate', $listener->handle( 'completed', (int) $order['woo_order_id'] ) );
		$this->assertSame( 'duplicate', $listener->replay( (int) $order['id'] ) );
		$this->assertSame( $end, wvm( SubscriptionRepository::class )->find( (int) $sub['id'] )['current_period_end'] );
		$this->assertSame( 1, wvm( PaymentRepository::class )->count( array( 'order_id' => (int) $order['id'] ) ) );

		global $wpdb;
		$inbox = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'hook_inbox' ) . ' WHERE woo_order_id = %d', (int) $order['woo_order_id'] ) );
		$this->assertGreaterThanOrEqual( 3, $inbox, 'Every Woo status event is written to the hook inbox.' );
	}

	public function test_amount_mismatch_goes_to_review_without_activation(): void {
		$member = $this->member();
		$order  = wvm( OrderService::class )->createForPlan( (int) $member['id'], (int) $this->plan( 'basic' )['id'], 'new', 'UGX' );
		$sent   = array();
		add_filter(
			'pre_wp_mail',
			static function ( $null, $atts ) use ( &$sent ) {
				$sent[] = $atts['subject'];
				return true;
			},
			5,
			2
		);
		$this->payWoo( (int) $order['woo_order_id'], 100.0 );
		$after = wvm( OrderService::class )->get( (int) $order['id'] );
		$this->assertSame( 'needs_review', $after['status'] );
		$this->assertStringStartsWith( 'amount_mismatch', (string) $after['review_reason'] );
		$this->assertSame( 'pending', wvm( SubscriptionRepository::class )->find( (int) $order['subscription_id'] )['status'] );
		$this->assertNotEmpty( array_filter( $sent, static fn( $s ) => str_contains( (string) $s, 'needs review' ) ), 'Admin alert sent.' );
	}

	public function test_currency_mismatch_goes_to_review(): void {
		$member = $this->member();
		$order  = wvm( OrderService::class )->createForPlan( (int) $member['id'], (int) $this->plan( 'basic' )['id'], 'new', 'UGX' );
		$wc     = wc_get_order( (int) $order['woo_order_id'] );
		$wc->set_currency( 'USD' );
		$wc->save();
		$this->payWoo( (int) $order['woo_order_id'] );
		$after = wvm( OrderService::class )->get( (int) $order['id'] );
		$this->assertSame( 'needs_review', $after['status'] );
		$this->assertStringStartsWith( 'currency_mismatch', (string) $after['review_reason'] );
	}

	public function test_admin_approval_activates_review_order(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$member = $this->member();
		$order  = wvm( OrderService::class )->createForPlan( (int) $member['id'], (int) $this->plan( 'basic' )['id'], 'new', 'UGX' );
		$this->payWoo( (int) $order['woo_order_id'], 19000.0 );
		wvm( OrderService::class )->approveReview( (int) $order['id'], 'Customer paid short, accepted' );
		$this->assertSame( 'paid', wvm( OrderService::class )->get( (int) $order['id'] )['status'] );
		$this->assertSame( 'active', wvm( SubscriptionRepository::class )->find( (int) $order['subscription_id'] )['status'] );
	}

	public function test_non_plugin_orders_are_ignored(): void {
		$wc = wc_create_order();
		$wc->set_total( '5000' );
		$wc->save();
		$this->assertSame( 'ignored', wvm( OrderListener::class )->handle( 'processing', $wc->get_id() ) );
	}
}
