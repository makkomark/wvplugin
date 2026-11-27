<?php
namespace WitsValley\Members\Tests\Integration;

require_once __DIR__ . '/KitSyncTest.php';

use WitsValley\Members\Domain\Kit\KitClient;
use WitsValley\Members\Domain\Kit\KitSync;
use WitsValley\Members\Domain\Member\MemberRepository;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Popup\PopupService;
use WitsValley\Members\Domain\Subscription\SubscriptionService;
use WitsValley\Members\Jobs\Scheduler;
use WitsValley\Members\Plugin;
use WitsValley\Members\Support\Settings;
use WitsValley\Members\Tests\TestCase;

/** Membership popup: audience, free-book tag, email-only leads, stats. */
final class PopupTest extends TestCase {

	private FakeKit $kit;
	private int $post;

	public function set_up(): void {
		parent::set_up();
		$this->kit = new FakeKit( wvm( Settings::class ), wvm( \WitsValley\Members\Domain\Kit\KitLogRepository::class ) );
		Plugin::instance()->container()->set( KitClient::class, $this->kit );
		wvm( Settings::class )->set( 'popup', 'enabled', true );
		$this->post = self::factory()->post->create( array( 'post_title' => 'A lesson', 'post_status' => 'publish' ) );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_visitors_see_the_offer_free_members_the_upgrade_and_paying_members_nothing(): void {
		$popup = wvm( PopupService::class );
		$this->go_to( get_permalink( $this->post ) );
		$cfg = $popup->forRequest();
		$this->assertSame( 'join', $cfg['kind'] );
		$this->assertSame( 'join', $cfg['mode'] );
		$this->assertSame( (int) $this->plan( 'free' )['id'], $cfg['freePlanId'] );

		$member = $this->member();
		wvm( SubscriptionService::class )->startFree( (int) $member['id'] );
		wp_set_current_user( (int) $member['user_id'] );
		$this->assertSame( 'upgrade', $popup->forRequest()['kind'] );

		$order = wvm( OrderService::class )->createForPlan( (int) $member['id'], (int) $this->plan( 'basic' )['id'], 'new', 'UGX' );
		$this->payWoo( (int) $order['woo_order_id'] );
		$this->assertNull( $popup->forRequest(), 'Paying members never see the popup.' );

		wvm( Settings::class )->set( 'popup', 'enabled', false );
		wp_set_current_user( 0 );
		$this->assertNull( $popup->forRequest() );
	}

	public function test_never_on_the_membership_pages_or_excluded_paths(): void {
		$popup = wvm( PopupService::class );
		\WitsValley\Members\Install\Seeder::pages( true );
		wvm( Settings::class )->flush();
		$pricing = (int) wvm( Settings::class )->get( 'general', 'pricing_page_id', 0 );
		$this->assertGreaterThan( 0, $pricing );
		$this->go_to( get_permalink( $pricing ) );
		$this->assertNull( $popup->forRequest() );

		wvm( Settings::class )->set( 'popup', 'post_types', array( 'page' ) );
		$this->go_to( get_permalink( $this->post ) );
		$this->assertNull( $popup->forRequest(), 'Posts not selected.' );

		wvm( Settings::class )->set( 'popup', 'post_types', array( 'post' ) );
		$this->set_permalink_structure( '/%postname%/' );
		$slug = get_post_field( 'post_name', $this->post );
		wvm( Settings::class )->set( 'popup', 'exclude_paths', array( '/' . $slug ) );
		$this->go_to( get_permalink( $this->post ) );
		$this->assertNull( $popup->forRequest() );
	}

	public function test_popup_signup_gets_the_book_tag_even_without_free_members_in_kit(): void {
		wvm( Settings::class )->set( 'kit', 'create_subscribers_on_free', false );
		$member = $this->member();
		$mid    = (int) $member['id'];
		wvm( SubscriptionService::class )->startFree( $mid );
		wvm( PopupService::class )->joined( (int) $member['user_id'], 'b' );

		$res = wvm( KitSync::class )->syncMember( $mid );
		$this->assertSame( 'ok', $res['status'] );
		$sid  = (string) wvm( MemberRepository::class )->find( $mid )['kit_subscriber_id'];
		$tags = $this->kit->subscribers[ $sid ]['tags'];
		sort( $tags );
		$this->assertSame( array( 'WV-FREE', 'WV-FREE-BOOK' ), $tags );
		$this->assertSame( 'popup', get_user_meta( (int) $member['user_id'], 'wvm_signup_source', true ) );
		$this->assertSame( 1, wvm( PopupService::class )->stats()['variants']['b']['signup'] );

		// Upgrading later keeps the book tag (it is not part of any state rule).
		$order = wvm( OrderService::class )->createForPlan( $mid, (int) $this->plan( 'premium' )['id'], 'new', 'UGX' );
		$this->payWoo( (int) $order['woo_order_id'] );
		wvm( KitSync::class )->syncMember( $mid );
		$tags = $this->kit->subscribers[ $sid ]['tags'];
		sort( $tags );
		$this->assertSame( array( 'WV-FREE-BOOK', 'WV-PREMIUM' ), $tags );
		$this->assertSame( 1, wvm( PopupService::class )->stats()['variants']['b']['paid'] );
	}

	public function test_email_only_lead_is_queued_and_tagged_in_kit(): void {
		$popup = wvm( PopupService::class );
		$popup->lead( 'Amos', 'Amos@Example.org', 'a' );
		$this->assertTrue( as_has_scheduled_action( Scheduler::KIT_LEAD, array( 'amos@example.org', 'Amos' ), Scheduler::GROUP ) );
		$popup->deliverLead( 'amos@example.org', 'Amos' );
		$sub  = $this->kit->findSubscriberByEmail( 'amos@example.org' );
		$tags = $this->kit->subscribers[ $sub['id'] ]['tags'];
		sort( $tags );
		$this->assertSame( array( 'WV-FREE-BOOK', 'WV-LEAD' ), $tags );
		$this->assertSame( 1, $popup->stats()['variants']['a']['lead'] );

		$this->expectException( \WitsValley\Members\Support\DomainError::class );
		$popup->lead( 'Bad', 'not-an-email', 'a' );
	}

	public function test_events_are_counted_and_conversion_computed(): void {
		$popup = wvm( PopupService::class );
		foreach ( range( 1, 4 ) as $i ) {
			$popup->record( 'view', 'a' );
		}
		$popup->record( 'close', 'a' );
		$popup->record( 'signup', 'a' );
		$popup->record( 'view', 'upgrade' );
		$popup->record( 'upgrade_click', 'upgrade' );
		$s = $popup->stats( 7 )['variants'];
		$this->assertSame( 4, $s['a']['view'] );
		$this->assertSame( 25.0, $s['a']['rate'] );
		$this->assertSame( 100.0, $s['upgrade']['rate'] );

		$this->expectException( \WitsValley\Members\Support\DomainError::class );
		$popup->record( 'hack', 'a' );
	}
}
