<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Kit;

use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Member\MemberRepository;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Settings;

/**
 * Kit → plugin webhooks. When someone unsubscribes, bounces or complains in Kit, the member's
 * marketing consent is withdrawn and the plugin stops creating or re-subscribing them.
 * When they subscribe again (e.g. through a Kit form), the block is lifted.
 *
 * Endpoint: POST /wp-json/wv-members/v1/public/kit/webhook/{secret}/{event}
 */
final class KitWebhooks {

	/** URL event slug => Kit event name. */
	public const EVENTS = array(
		'unsubscribe' => 'subscriber.subscriber_unsubscribe',
		'bounce'      => 'subscriber.subscriber_bounce',
		'complain'    => 'subscriber.subscriber_complain',
		'activate'    => 'subscriber.subscriber_activate',
	);

	public function __construct(
		private KitClient $client,
		private Settings $settings,
		private MemberRepository $members,
		private KitLogRepository $log
	) {}

	public function url( string $secret, string $event ): string {
		return rest_url( WVM_REST_NS . '/public/kit/webhook/' . $secret . '/' . $event );
	}

	/**
	 * Create (or re-create) the four webhooks in Kit.
	 *
	 * @return array{connected:bool, webhooks:int, connected_at:?string}
	 */
	public function connect(): array {
		if ( ! $this->client->configured() ) {
			throw DomainError::validation( array( 'api_key' => 'Add your Kit API key first.' ) );
		}
		$this->removeExisting();
		$secret = bin2hex( random_bytes( 24 ) );
		$ids    = array();
		foreach ( self::EVENTS as $slug => $event ) {
			$id = $this->client->createWebhook( $this->url( $secret, $slug ), $event );
			if ( ! $id ) {
				foreach ( $ids as $created ) {
					$this->client->deleteWebhook( $created );
				}
				throw new DomainError( 'Kit did not accept the webhook. Kit must be able to reach this site over public HTTPS.', 'wvm_kit_webhook_failed', 502 );
			}
			$ids[] = $id;
		}
		$this->settings->set( 'kit', 'webhook_secret', $secret );
		$this->settings->set( 'kit', 'webhook_ids', $ids );
		$this->settings->set( 'kit', 'webhooks_connected_at', Clock::mysql() );
		wvm( Audit::class )->log( 'kit.webhooks_connected', 'settings', null, null, array( 'webhooks' => count( $ids ) ) );
		return $this->state();
	}

	/** @return array{connected:bool, webhooks:int, connected_at:?string} */
	public function disconnect(): array {
		if ( $this->client->configured() ) {
			$this->removeExisting();
		}
		$this->settings->set( 'kit', 'webhook_secret', '' );
		$this->settings->set( 'kit', 'webhook_ids', array() );
		$this->settings->set( 'kit', 'webhooks_connected_at', null );
		wvm( Audit::class )->log( 'kit.webhooks_disconnected', 'settings', null );
		return $this->state();
	}

	/** @return array{connected:bool, webhooks:int, connected_at:?string} */
	public function state(): array {
		return array(
			'connected'    => (bool) $this->settings->get( 'kit', 'webhooks_connected_at' ),
			'webhooks'     => count( (array) $this->settings->get( 'kit', 'webhook_ids', array() ) ),
			'connected_at' => Clock::iso( $this->settings->get( 'kit', 'webhooks_connected_at' ) ),
		);
	}

	/** Delete the webhooks we created before, plus any stray ones pointing at this site's endpoint. */
	private function removeExisting(): void {
		foreach ( (array) $this->settings->get( 'kit', 'webhook_ids', array() ) as $id ) {
			$this->client->deleteWebhook( (int) $id );
		}
		$base = rest_url( WVM_REST_NS . '/public/kit/webhook/' );
		foreach ( $this->client->listWebhooks() as $hook ) {
			$target = (string) ( $hook['target_url'] ?? '' );
			if ( '' !== $target && str_starts_with( $target, $base ) && ! empty( $hook['id'] ) ) {
				$this->client->deleteWebhook( (int) $hook['id'] );
			}
		}
	}

	/**
	 * Handle a delivery from Kit.
	 *
	 * @param array<string,mixed> $payload
	 * @return array{status:string, member_id?:int}
	 */
	public function handle( string $secret, string $event, array $payload ): array {
		$expected = (string) $this->settings->get( 'kit', 'webhook_secret', '' );
		if ( '' === $expected || ! hash_equals( $expected, $secret ) ) {
			throw new DomainError( 'Unknown webhook.', 'wvm_not_found', 404 );
		}
		if ( ! isset( self::EVENTS[ $event ] ) ) {
			throw new DomainError( 'Unknown event.', 'wvm_not_found', 404 );
		}
		$sub    = (array) ( $payload['subscriber'] ?? array() );
		$member = $this->findMember( (string) ( $sub['id'] ?? '' ), (string) ( $sub['email_address'] ?? '' ) );
		if ( ! $member ) {
			// Kit subscribers that aren't members (newsletter-only sign-ups) are none of our business.
			return array( 'status' => 'ignored' );
		}
		$mid = (int) $member['id'];
		if ( 'activate' === $event ) {
			$this->members->update(
				$mid,
				array(
					'kit_unsubscribed_at'    => null,
					'kit_unsubscribe_reason' => null,
					'kit_subscriber_id'      => $member['kit_subscriber_id'] ?: ( isset( $sub['id'] ) ? (string) $sub['id'] : null ),
				)
			);
		} else {
			$row = array(
				'kit_unsubscribed_at'    => Clock::mysql(),
				'kit_unsubscribe_reason' => $event,
			);
			if ( $member['marketing_consent'] ) {
				$row['marketing_consent']    = false;
				$row['marketing_consent_at'] = Clock::mysql();
			}
			$this->members->update( $mid, $row );
		}
		$this->log->insert(
			array(
				'member_id' => $mid,
				'action'    => 'webhook',
				'tag'       => self::EVENTS[ $event ],
				'status'    => 'ok',
				'response'  => null,
			)
		);
		/**
		 * A Kit subscriber event was applied to a member.
		 *
		 * @param int    $member_id
		 * @param string $event     unsubscribe|bounce|complain|activate
		 */
		do_action( 'wvm/kit/subscriber_event', $mid, $event );
		return array(
			'status'    => 'ok',
			'member_id' => $mid,
		);
	}

	/** @return array<string,mixed>|null */
	private function findMember( string $subscriberId, string $email ): ?array {
		if ( '' !== $subscriberId ) {
			$m = $this->members->findBy( 'kit_subscriber_id', $subscriberId );
			if ( $m ) {
				return $m;
			}
		}
		if ( '' !== $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				return $this->members->byUser( (int) $user->ID );
			}
		}
		return null;
	}
}
