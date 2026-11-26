<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Kit;

use WitsValley\Members\Support\Http;
use WitsValley\Members\Support\Settings;

/**
 * Kit (ConvertKit) API v4 client. Every call is logged to wvm_kit_sync_log.
 */
class KitClient {

	public const BASE = 'https://api.kit.com/v4';

	public function __construct( private Settings $settings, private KitLogRepository $log ) {}

	public function configured(): bool {
		return '' !== (string) $this->settings->get( 'kit', 'api_key', '' );
	}

	/** @return array{status:int, body:mixed, raw:string, error:?string} */
	protected function request( string $method, string $path, ?array $body = null ): array {
		$key = (string) $this->settings->get( 'kit', 'api_key', '' );
		$res = Http::json( $method, self::BASE . $path, array( 'X-Kit-Api-Key' => $key ), $body );
		if ( 429 === $res['status'] ) {
			// Kit allows ~120 requests per rolling minute per API key; back off and let the job retry.
			throw new KitRateLimited( 60 );
		}
		return $res;
	}

	/** @return array<string,mixed>|null */
	public function account(): ?array {
		$res = $this->request( 'GET', '/account' );
		return $res['status'] >= 200 && $res['status'] < 300 ? (array) $res['body'] : null;
	}

	/** @return array<string,mixed>|null */
	public function findSubscriberByEmail( string $email, ?int $memberId = null ): ?array {
		$res = $this->request( 'GET', '/subscribers?status=all&email_address=' . rawurlencode( strtolower( $email ) ) );
		$this->logCall( $memberId, 'lookup', null, $res );
		$subs = is_array( $res['body'] ) ? (array) ( $res['body']['subscribers'] ?? array() ) : array();
		foreach ( $subs as $sub ) {
			if ( 0 === strcasecmp( (string) ( $sub['email_address'] ?? '' ), $email ) ) {
				return (array) $sub;
			}
		}
		return null;
	}

	/** @return array<string,mixed>|null */
	public function createSubscriber( string $email, string $firstName, string $state = 'active', ?int $memberId = null ): ?array {
		$res = $this->request(
			'POST',
			'/subscribers',
			array(
				'email_address' => strtolower( $email ),
				'first_name'    => $firstName,
				'state'         => $state,
			)
		);
		$this->logCall( $memberId, 'create', null, $res );
		return is_array( $res['body'] ) && isset( $res['body']['subscriber'] ) ? (array) $res['body']['subscriber'] : null;
	}

	public function updateSubscriber( string $subscriberId, array $fields, ?int $memberId = null ): bool {
		$res = $this->request( 'PUT', '/subscribers/' . rawurlencode( $subscriberId ), $fields );
		$this->logCall( $memberId, 'create', null, $res );
		return $res['status'] >= 200 && $res['status'] < 300;
	}

	/** @return list<string> Tag names on a subscriber. */
	public function subscriberTags( string $subscriberId, ?int $memberId = null ): array {
		$res = $this->request( 'GET', '/subscribers/' . rawurlencode( $subscriberId ) . '/tags?per_page=1000' );
		$this->logCall( $memberId, 'lookup', null, $res );
		$tags = is_array( $res['body'] ) ? (array) ( $res['body']['tags'] ?? array() ) : array();
		return array_values( array_map( static fn( $t ) => (string) $t['name'], $tags ) );
	}

	public function addTag( string $subscriberId, int $tagId, string $tagName, ?int $memberId = null ): bool {
		$res = $this->request( 'POST', '/tags/' . $tagId . '/subscribers/' . rawurlencode( $subscriberId ), array() );
		$this->logCall( $memberId, 'add_tag', $tagName, $res );
		return $res['status'] >= 200 && $res['status'] < 300;
	}

	public function removeTag( string $subscriberId, int $tagId, string $tagName, ?int $memberId = null ): bool {
		$res = $this->request( 'DELETE', '/tags/' . $tagId . '/subscribers/' . rawurlencode( $subscriberId ) );
		$this->logCall( $memberId, 'remove_tag', $tagName, $res );
		return ( $res['status'] >= 200 && $res['status'] < 300 ) || 404 === $res['status'];
	}

	/** @return array<string,int> name => id */
	public function listTags( bool $fresh = false ): array {
		$cached = get_transient( 'wvm_kit_tags' );
		if ( ! $fresh && is_array( $cached ) ) {
			return $cached;
		}
		$out    = array();
		$cursor = '';
		for ( $i = 0; $i < 20; $i++ ) {
			$res = $this->request( 'GET', '/tags?per_page=1000' . ( $cursor ? '&after=' . rawurlencode( $cursor ) : '' ) );
			if ( ! is_array( $res['body'] ) ) {
				break;
			}
			foreach ( (array) ( $res['body']['tags'] ?? array() ) as $tag ) {
				$out[ (string) $tag['name'] ] = (int) $tag['id'];
			}
			$page = (array) ( $res['body']['pagination'] ?? array() );
			if ( empty( $page['has_next_page'] ) ) {
				break;
			}
			$cursor = (string) ( $page['end_cursor'] ?? '' );
		}
		set_transient( 'wvm_kit_tags', $out, HOUR_IN_SECONDS );
		return $out;
	}

	public function tagId( string $name ): ?int {
		$tags = $this->listTags();
		if ( isset( $tags[ $name ] ) ) {
			return $tags[ $name ];
		}
		$res = $this->request( 'POST', '/tags', array( 'name' => $name ) );
		$id  = is_array( $res['body'] ) ? (int) ( $res['body']['tag']['id'] ?? 0 ) : 0;
		delete_transient( 'wvm_kit_tags' );
		return $id ?: null;
	}

	/** @return list<array<string,mixed>> */
	public function listBroadcasts( ?string $since = null ): array {
		$res = $this->request( 'GET', '/broadcasts?per_page=50' );
		$all = is_array( $res['body'] ) ? (array) ( $res['body']['broadcasts'] ?? array() ) : array();
		if ( $since ) {
			$all = array_filter( $all, static fn( $b ) => strtotime( (string) ( $b['created_at'] ?? '' ) ) >= strtotime( $since ) );
		}
		return array_values( $all );
	}

	/** @param array{status:int, body:mixed, raw:string, error:?string} $res */
	private function logCall( ?int $memberId, string $action, ?string $tag, array $res ): void {
		$ok = $res['status'] >= 200 && $res['status'] < 300;
		$this->log->insert(
			array(
				'member_id' => $memberId,
				'action'    => $action,
				'tag'       => $tag,
				'status'    => $ok ? 'ok' : 'error',
				'response'  => $ok ? null : mb_substr( (string) ( $res['error'] ?? '' ) . ' ' . $res['raw'], 0, 1000 ),
			)
		);
	}
}
