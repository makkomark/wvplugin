<?php
/**
 * Base integration test case with factories for members, orders and paid subscriptions.
 *
 * @package WitsValley\Members\Tests
 */

namespace WitsValley\Members\Tests;

use WitsValley\Members\Domain\Access\LevelResolver;
use WitsValley\Members\Domain\Member\MemberService;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Plan\PlanService;
use WitsValley\Members\Domain\Subscription\SubscriptionRepository;
use WitsValley\Members\Plugin;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Settings;

abstract class TestCase extends \WP_UnitTestCase {

	protected static int $seq = 0;

	public function set_up(): void {
		parent::set_up();
		Clock::unfreeze();
		Plugin::instance()->reset();
		wvm( Settings::class )->flush();
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	public function tear_down(): void {
		Clock::unfreeze();
		remove_all_filters( 'pre_wp_mail' );
		parent::tear_down();
	}

	/** @return array<string,mixed> */
	protected function member( array $overrides = array() ): array {
		++self::$seq;
		return wvm( MemberService::class )->createFromSignup(
			array_merge(
				array(
					'name'     => 'Test Member ' . self::$seq,
					'email'    => 'member' . self::$seq . '-' . wp_generate_password( 6, false ) . '@example.org',
					'password' => 'secret123',
					'country'  => 'UG',
				),
				$overrides
			)
		);
	}

	/** @return array<string,mixed> */
	protected function plan( string $slug ): array {
		$plan = wvm( PlanService::class )->findBySlug( $slug );
		$this->assertNotNull( $plan, "Seeded plan {$slug} missing" );
		return $plan;
	}

	/** Pay a Woo order the way a gateway would. */
	protected function payWoo( int $wooId, ?float $total = null, string $gateway = 'wvm_test' ): \WC_Order {
		$wc = wc_get_order( $wooId );
		$wc->set_payment_method( $gateway );
		if ( null !== $total ) {
			$wc->set_total( (string) $total );
		}
		$wc->save();
		$wc->payment_complete( 'TX-' . wp_generate_password( 10, false ) );
		return wc_get_order( $wooId );
	}

	/**
	 * Create a member with a paid, active subscription.
	 *
	 * @return array{member:array<string,mixed>, order:array<string,mixed>, sub:array<string,mixed>}
	 */
	protected function paidMember( string $planSlug = 'basic', string $currency = 'UGX' ): array {
		$member = $this->member();
		$order  = wvm( OrderService::class )->createForPlan( (int) $member['id'], (int) $this->plan( $planSlug )['id'], 'new', $currency );
		$this->payWoo( (int) $order['woo_order_id'] );
		$sub = wvm( SubscriptionRepository::class )->current( (int) $member['id'] );
		wvm( LevelResolver::class )->flush();
		return array( 'member' => $member, 'order' => wvm( OrderService::class )->get( (int) $order['id'] ), 'sub' => $sub );
	}

	/** @return array<string,mixed> */
	protected function sub( int $id ): array {
		return wvm( SubscriptionRepository::class )->find( $id );
	}

	protected function staff( string $role ): int {
		return self::factory()->user->create( array( 'role' => $role ) );
	}
}
