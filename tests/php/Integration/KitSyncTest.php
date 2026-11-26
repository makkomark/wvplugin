<?php
namespace WitsValley\Members\Tests\Integration;

use WitsValley\Members\Domain\Kit\KitClient;
use WitsValley\Members\Domain\Kit\KitSync;
use WitsValley\Members\Domain\Kit\TagMap;
use WitsValley\Members\Domain\Subscription\Dunning;
use WitsValley\Members\Domain\Subscription\SubscriptionService;
use WitsValley\Members\Plugin;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Settings;
use WitsValley\Members\Tests\TestCase;

/** In-memory Kit used by the tests. */
final class FakeKit extends KitClient {
	/** @var array<string,array{email:string,tags:list<string>}> */
	public array $subscribers = array();
	/** @var array<string,int> */
	public array $tags  = array();
	public int $created = 0;
	public bool $rateLimited = false;

	public function configured(): bool {
		return true;
	}
	public function findSubscriberByEmail( string $email, ?int $memberId = null ): ?array {
		foreach ( $this->subscribers as $id => $s ) {
			if ( 0 === strcasecmp( $s['email'], $email ) ) {
				return array(
					'id'            => $id,
					'email_address' => $s['email'],
				);
			}
		}
		return null;
	}
	public function createSubscriber( string $email, string $firstName, string $state = 'active', ?int $memberId = null ): ?array {
		++$this->created;
		$id                       = (string) ( 1000 + $this->created );
		$this->subscribers[ $id ] = array(
			'email' => $email,
			'tags'  => array(),
		);
		return array( 'id' => $id );
	}
	public function updateSubscriber( string $subscriberId, array $fields, ?int $memberId = null ): bool {
		return true;
	}
	public function subscriberTags( string $subscriberId, ?int $memberId = null ): array {
		if ( $this->rateLimited ) {
			throw new \WitsValley\Members\Domain\Kit\KitRateLimited( 60 );
		}
		return $this->subscribers[ $subscriberId ]['tags'] ?? array();
	}
	public function tagId( string $name ): ?int {
		$this->tags[ $name ] = $this->tags[ $name ] ?? count( $this->tags ) + 1;
		return $this->tags[ $name ];
	}
	public function addTag( string $subscriberId, int $tagId, string $tagName, ?int $memberId = null ): bool {
		$this->subscribers[ $subscriberId ]['tags'][] = $tagName;
		$this->subscribers[ $subscriberId ]['tags']   = array_values( array_unique( $this->subscribers[ $subscriberId ]['tags'] ) );
		return true;
	}
	public function removeTag( string $subscriberId, int $tagId, string $tagName, ?int $memberId = null ): bool {
		$this->subscribers[ $subscriberId ]['tags'] = array_values( array_diff( $this->subscribers[ $subscriberId ]['tags'], array( $tagName ) ) );
		return true;
	}
}

/** Acceptance #6: tags after each state match TagMap; one subscriber; non-WV tags untouched. */
final class KitSyncTest extends TestCase {

	private FakeKit $kit;

	public function set_up(): void {
		parent::set_up();
		$this->kit = new FakeKit( wvm( Settings::class ), wvm( \WitsValley\Members\Domain\Kit\KitLogRepository::class ) );
		Plugin::instance()->container()->set( KitClient::class, $this->kit );
	}

	/** @return list<string> */
	private function tagsOf( int $memberId ): array {
		$sid  = (string) wvm( \WitsValley\Members\Domain\Member\MemberRepository::class )->find( $memberId )['kit_subscriber_id'];
		$tags = $this->kit->subscribers[ $sid ]['tags'];
		sort( $tags );
		return $tags;
	}

	public function test_tags_follow_state_and_free_to_paid_keeps_one_subscriber(): void {
		$member = $this->member( array( 'marketing_consent' => true ) );
		$mid    = (int) $member['id'];
		wvm( SubscriptionService::class )->startFree( $mid );
		wvm( KitSync::class )->syncMember( $mid );
		$this->assertSame( array( 'WV-FREE' ), $this->tagsOf( $mid ) );

		// Someone else added a non-WV tag in Kit: must be preserved.
		$sid                                      = array_key_first( $this->kit->subscribers );
		$this->kit->subscribers[ $sid ]['tags'][] = 'Webinar-2025';

		$order = wvm( \WitsValley\Members\Domain\Order\OrderService::class )->createForPlan( $mid, (int) $this->plan( 'basic' )['id'], 'new', 'UGX' );
		$this->payWoo( (int) $order['woo_order_id'] );
		wvm( KitSync::class )->syncMember( $mid );
		$this->assertSame( array( 'WV-BASIC', 'Webinar-2025' ), $this->tagsOf( $mid ) );
		$this->assertSame( 1, $this->kit->created, 'Free → paid results in exactly one Kit subscriber.' );

		$sub = wvm( \WitsValley\Members\Domain\Subscription\SubscriptionRepository::class )->current( $mid );
		Clock::freeze( Clock::parse( $sub['current_period_end'] )->modify( '+1 minute' ) );
		wvm( Dunning::class )->renewalDue( (int) $sub['id'] );
		wvm( KitSync::class )->syncMember( $mid );
		$this->assertSame( array( 'WV-BASIC', 'WV-PAST-DUE', 'Webinar-2025' ), $this->tagsOf( $mid ) );

		Clock::freeze( Clock::parse( $sub['current_period_end'] )->modify( '+8 days' ) );
		wvm( Dunning::class )->endGrace( (int) $sub['id'] );
		wvm( KitSync::class )->syncMember( $mid );
		$this->assertSame( array( 'WV-EXPIRED', 'Webinar-2025' ), $this->tagsOf( $mid ) );

		$expected = TagMap::apply( array( 'WV-BASIC', 'WV-PAST-DUE' ), wvm( TagMap::class )->resolve( 'suspended', 'WV-BASIC' ) );
		$this->assertSame( array( 'WV-EXPIRED' ), $expected );
	}

	public function test_member_without_consent_on_free_is_not_created(): void {
		$member = $this->member( array( 'marketing_consent' => false ) );
		wvm( SubscriptionService::class )->startFree( (int) $member['id'] );
		$res = wvm( KitSync::class )->syncMember( (int) $member['id'] );
		$this->assertSame( 'no_subscriber', $res['status'] );
		$this->assertSame( 0, $this->kit->created );
	}

	public function test_rate_limit_is_retried_later_not_marked_as_error(): void {
		$member = $this->member( array( 'marketing_consent' => true ) );
		wvm( SubscriptionService::class )->startFree( (int) $member['id'] );
		wvm( KitSync::class )->syncMember( (int) $member['id'] );
		$this->kit->rateLimited = true;
		$res = wvm( KitSync::class )->syncMember( (int) $member['id'] );
		$this->assertSame( 'rate_limited', $res['status'] );
		$this->assertNull( wvm( \WitsValley\Members\Domain\Member\MemberRepository::class )->find( (int) $member['id'] )['kit_sync_error'] );
		$this->assertTrue( as_has_scheduled_action( \WitsValley\Members\Jobs\Scheduler::KIT_SYNC, array( (int) $member['id'] ), \WitsValley\Members\Jobs\Scheduler::GROUP ), 'A retry is scheduled.' );
	}

	public function test_reconcile_is_paced_under_kit_limits(): void {
		for ( $i = 0; $i < 60; $i++ ) {
			$this->member();
		}
		$queued = wvm( KitSync::class )->reconcile();
		$this->assertGreaterThanOrEqual( 60, $queued );
		$ids     = as_get_scheduled_actions( array( 'hook' => \WitsValley\Members\Jobs\Scheduler::KIT_SYNC, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 500 ), 'ids' );
		$store   = \ActionScheduler::store();
		$perMin  = array();
		foreach ( $ids as $id ) {
			$ts = $store->fetch_action( (int) $id )->get_schedule()->get_date()->getTimestamp();
			$perMin[ (int) floor( ( $ts - time() ) / 60 ) ] = ( $perMin[ (int) floor( ( $ts - time() ) / 60 ) ] ?? 0 ) + 1;
		}
		$this->assertLessThanOrEqual( KitSync::MEMBERS_PER_MINUTE, max( $perMin ), 'No more than 25 member syncs per minute (≈50–100 API calls).' );
	}
}
