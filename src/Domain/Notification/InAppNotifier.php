<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Notification;

use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\DomainError;

/**
 * In-app (bell) notifications.
 */
final class InAppNotifier {

	public function __construct( private InAppRepository $repo ) {}

	public function push( int $memberId, string $type, string $title, string $body = '', ?string $url = null ): int {
		return $this->repo->insert(
			array(
				'member_id' => $memberId,
				'type'      => sanitize_key( $type ),
				'title'     => mb_substr( $title, 0, 200 ),
				'body'      => $body,
				'url'       => $url,
			)
		);
	}

	/** @return list<array<string,mixed>> */
	public function list( int $memberId, bool $unreadOnly = false, int $limit = 30 ): array {
		$where = array( 'member_id' => $memberId );
		if ( $unreadOnly ) {
			$where['read_at'] = null;
		}
		return array_map(
			static fn( array $n ): array => array(
				'id'         => (int) $n['id'],
				'type'       => $n['type'],
				'title'      => $n['title'],
				'body'       => $n['body'],
				'url'        => $n['url'],
				'read'       => null !== $n['read_at'],
				'created_at' => Clock::iso( $n['created_at'] ),
			),
			$this->repo->where( $where, 'id DESC', $limit )
		);
	}

	public function unreadCount( int $memberId ): int {
		return $this->repo->count( array( 'member_id' => $memberId, 'read_at' => null ) );
	}

	public function markRead( int $memberId, int $id ): void {
		$n = $this->repo->find( $id );
		if ( ! $n || (int) $n['member_id'] !== $memberId ) {
			throw DomainError::notFound( 'notification' );
		}
		$this->repo->update( $id, array( 'read_at' => Clock::mysql() ) );
	}

	public function markAllRead( int $memberId ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'in_app_notifications' ) . ' SET read_at = %s WHERE member_id = %d AND read_at IS NULL', Clock::mysql(), $memberId ) ); // phpcs:ignore WordPress.DB
	}
}
