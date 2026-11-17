<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Kit;

use WitsValley\Members\Domain\Member\MemberRepository;
use WitsValley\Members\Domain\Plan\PlanService;
use WitsValley\Members\Domain\Subscription\SubscriptionRepository;
use WitsValley\Members\Jobs\Scheduler;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\Pagination;
use WitsValley\Members\Support\Settings;

/**
 * Keeps each member's WV-* tags in Kit equal to the TagMap expectation for their state.
 */
final class KitSync {

	public function __construct(
		private KitClient $client,
		private TagMap $map,
		private MemberRepository $members,
		private SubscriptionRepository $subs,
		private PlanService $plans,
		private Settings $settings,
		private Scheduler $scheduler,
		private KitLogRepository $log
	) {}

	public function register(): void {
		add_action( 'wvm/subscription/changed', fn( $sub ) => $this->queue( (int) $sub['member_id'] ) );
		add_action( 'wvm/member/consent_changed', fn( $m ) => $this->queue( (int) $m['id'] ) );
		add_action( 'wvm/member/created', fn( $m ) => $this->queue( (int) $m['id'] ) );
		add_action( 'wvm/member/email_changed', array( $this, 'emailChanged' ) );
		add_action( 'wvm/member/bulk_tag', fn( $id, $tag ) => $this->scheduler->async( Scheduler::KIT_TAG, array( (int) $id, (string) $tag ) ), 10, 2 );
	}

	/** @param array<string,mixed> $member Presented member (new email). */
	public function emailChanged( array $member ): void {
		if ( $this->client->configured() && ! empty( $member['kit_subscriber_id'] ) ) {
			$this->client->updateSubscriber( (string) $member['kit_subscriber_id'], array( 'email_address' => strtolower( (string) $member['email'] ) ), (int) $member['id'] );
		}
		$this->queue( (int) $member['id'] );
	}

	public function queue( int $memberId ): void {
		if ( $this->client->configured() ) {
			$this->scheduler->unique( Scheduler::KIT_SYNC, array( $memberId ), 5 );
		}
	}

	/**
	 * Member state for the tag map.
	 *
	 * @return array{state:string, plan_tag:?string, extra:list<string>, paid:bool}
	 */
	public function stateFor( int $memberId ): array {
		$sub   = $this->subs->current( $memberId );
		$state = 'free';
		$tag   = null;
		$paid  = false;
		if ( $sub ) {
			$plan = $this->plans->get( (int) $sub['plan_id'] );
			if ( ! $plan['is_free'] ) {
				$state = (string) $sub['status'];
				$tag   = $plan['kit_tag'] ?: null;
				$paid  = true;
			} elseif ( 'cancelled' === $sub['status'] ) {
				$state = 'free';
			}
		}
		$extra = array();
		$seats = $this->subs->where(
			array(
				'member_id' => $memberId,
				'status'    => array( 'active', 'past_due' ),
			)
		);
		foreach ( $seats as $seat ) {
			if ( $seat['organisation_id'] ) {
				$plan = $this->plans->get( (int) $seat['plan_id'] );
				if ( $plan['kit_tag'] ) {
					$extra[] = (string) $plan['kit_tag'];
					$paid    = true;
					if ( in_array( $state, array( 'free', 'cancelled', 'expired', 'suspended' ), true ) ) {
						$state = 'active';
						$tag   = (string) $plan['kit_tag'];
					}
				}
			}
		}
		if ( ! in_array( $state, TagMap::STATES, true ) ) {
			$state = 'free';
		}
		return array(
			'state'    => $state,
			'plan_tag' => $tag,
			'extra'    => $extra,
			'paid'     => $paid,
		);
	}

	/**
	 * Sync one member. Returns summary.
	 *
	 * @return array<string,mixed>
	 */
	public function syncMember( int $memberId ): array {
		$member = $this->members->find( $memberId );
		if ( ! $member || $member['anonymised_at'] ) {
			return array( 'status' => 'skipped' );
		}
		if ( ! $this->client->configured() ) {
			return array( 'status' => 'not_configured' );
		}
		$user = get_userdata( (int) $member['user_id'] );
		if ( ! $user ) {
			return array( 'status' => 'skipped' );
		}
		$state = $this->stateFor( $memberId );
		try {
			$sid = $member['kit_subscriber_id'] ? (string) $member['kit_subscriber_id'] : null;
			if ( ! $sid ) {
				$found = $this->client->findSubscriberByEmail( (string) $user->user_email, $memberId );
				if ( $found ) {
					$sid = (string) $found['id'];
				} elseif ( $member['marketing_consent'] || ( $state['paid'] && $this->settings->get( 'kit', 'create_subscribers_on_paid', true ) ) ) {
					$created = $this->client->createSubscriber( (string) $user->user_email, (string) ( $user->first_name ?: $user->display_name ), 'active', $memberId );
					$sid     = $created ? (string) $created['id'] : null;
					if ( ! $sid ) {
						throw new \RuntimeException( 'Could not create Kit subscriber.' );
					}
				} else {
					$this->members->update(
						$memberId,
						array(
							'kit_sync_error'     => null,
							'kit_last_synced_at' => Clock::mysql(),
						)
					);
					return array( 'status' => 'no_subscriber' );
				}
			}
			$current  = $this->client->subscriberTags( $sid, $memberId );
			$rule     = $this->map->resolve( $state['state'], $state['plan_tag'], $state['extra'] );
			$expected = TagMap::apply( $current, $rule );
			$owned    = array_values( array_filter( $current, array( TagMap::class, 'isOwned' ) ) );
			$to_add   = array_values( array_diff( $expected, $owned ) );
			$to_rm    = array_values( array_diff( $owned, $expected ) );
			$errors   = array();
			foreach ( $to_add as $tag ) {
				$tid = $this->client->tagId( $tag );
				if ( ! $tid || ! $this->client->addTag( $sid, $tid, $tag, $memberId ) ) {
					$errors[] = 'add ' . $tag;
				}
			}
			foreach ( $to_rm as $tag ) {
				$tid = $this->client->tagId( $tag );
				if ( ! $tid || ! $this->client->removeTag( $sid, $tid, $tag, $memberId ) ) {
					$errors[] = 'remove ' . $tag;
				}
			}
			$this->members->update(
				$memberId,
				array(
					'kit_subscriber_id'  => $sid,
					'kit_last_synced_at' => Clock::mysql(),
					'kit_sync_error'     => $errors ? 'Failed: ' . implode( ', ', $errors ) : null,
				)
			);
			return array(
				'status'   => $errors ? 'partial' : 'ok',
				'state'    => $state['state'],
				'expected' => $expected,
				'added'    => $to_add,
				'removed'  => $to_rm,
			);
		} catch ( \Throwable $e ) {
			$this->members->update( $memberId, array( 'kit_sync_error' => mb_substr( $e->getMessage(), 0, 500 ) ) );
			return array(
				'status' => 'error',
				'error'  => $e->getMessage(),
			);
		}
	}

	/** Add an arbitrary (WV-*) tag — used by bulk actions. */
	public function addTag( int $memberId, string $tag ): void {
		$member = $this->members->find( $memberId );
		if ( ! $member || ! $this->client->configured() ) {
			return;
		}
		$user = get_userdata( (int) $member['user_id'] );
		$sid  = $member['kit_subscriber_id'] ?: ( $user ? ( $this->client->findSubscriberByEmail( (string) $user->user_email, $memberId )['id'] ?? null ) : null );
		if ( ! $sid ) {
			return;
		}
		$tid = $this->client->tagId( $tag );
		if ( $tid ) {
			$this->client->addTag( (string) $sid, $tid, $tag, $memberId );
		}
	}

	/** Daily: queue a sync for every member who has (or should have) a Kit subscriber. */
	public function reconcile(): int {
		if ( ! $this->client->configured() ) {
			return 0;
		}
		global $wpdb;
		$m     = Db::table( 'members' );
		$ids   = $wpdb->get_col( "SELECT id FROM {$m} WHERE anonymised_at IS NULL" ); // phpcs:ignore WordPress.DB
		$delay = 0;
		foreach ( $ids as $i => $id ) {
			$this->scheduler->unique( Scheduler::KIT_SYNC, array( (int) $id ), 5 + (int) floor( $i / 20 ) * 10 );
			++$delay;
		}
		$this->settings->set( 'kit', 'last_reconcile_at', Clock::mysql() );
		$this->settings->set( 'kit', 'last_reconcile_errors', $this->errorCount() );
		return count( $ids );
	}

	public function errorCount( int $hours = 24 ): int {
		global $wpdb;
		$t = Db::table( 'kit_sync_log' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'error' AND created_at >= %s", Clock::mysql( Clock::now()->modify( '-' . $hours . ' hours' ) ) ) ); // phpcs:ignore WordPress.DB
	}

	/** @return array<string,mixed> */
	public function status(): array {
		$connected = false;
		$account   = null;
		if ( $this->client->configured() ) {
			$cached = get_transient( 'wvm_kit_account' );
			if ( false === $cached ) {
				$account = $this->client->account();
				set_transient( 'wvm_kit_account', $account ?: array(), 10 * MINUTE_IN_SECONDS );
			} else {
				$account = $cached ?: null;
			}
			$connected = (bool) $account;
		}
		global $wpdb;
		$m = Db::table( 'members' );
		return array(
			'configured'          => $this->client->configured(),
			'connected'           => $connected,
			'account_name'        => $account['account']['name'] ?? ( $account['name'] ?? null ),
			'last_reconcile_at'   => Clock::iso( $this->settings->get( 'kit', 'last_reconcile_at' ) ),
			'error_count_24h'     => $this->errorCount(),
			'members_with_errors' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE kit_sync_error IS NOT NULL" ), // phpcs:ignore WordPress.DB
			'synced_members'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE kit_subscriber_id IS NOT NULL" ), // phpcs:ignore WordPress.DB
		);
	}

	/**
	 * @param array<string,mixed> $f
	 * @return array{items:list<array<string,mixed>>, meta:array<string,int>}
	 */
	public function logList( array $f, Pagination $p ): array {
		$cond = array();
		$args = array();
		if ( ! empty( $f['member'] ) ) {
			$cond[] = 'member_id = %d';
			$args[] = (int) $f['member'];
		}
		if ( ! empty( $f['status'] ) ) {
			$cond[] = 'status = %s';
			$args[] = (string) $f['status'];
		}
		$res          = $this->log->paginate( $cond, $args, $p );
		$res['items'] = array_map(
			static fn( array $r ): array => array(
				'id'         => (int) $r['id'],
				'member_id'  => $r['member_id'] ? (int) $r['member_id'] : null,
				'action'     => $r['action'],
				'tag'        => $r['tag'],
				'status'     => $r['status'],
				'response'   => $r['response'],
				'created_at' => Clock::iso( $r['created_at'] ),
			),
			$res['items']
		);
		return $res;
	}
}
