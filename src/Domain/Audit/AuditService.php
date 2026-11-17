<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Audit;

use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\Pagination;

/**
 * Read side of the audit log (list, member timeline, CSV).
 */
final class AuditService {

	public function __construct( private AuditRepository $repo ) {}

	/**
	 * @param array<string,mixed> $f entity_type, entity_id, actor, action, from, to
	 * @return array{items:list<array<string,mixed>>, meta:array<string,int>}
	 */
	public function list( array $f, Pagination $p ): array {
		$cond = array();
		$args = array();
		if ( ! empty( $f['entity_type'] ) ) {
			$cond[] = 'entity_type = %s';
			$args[] = (string) $f['entity_type'];
		}
		if ( ! empty( $f['entity_id'] ) ) {
			$cond[] = 'entity_id = %d';
			$args[] = (int) $f['entity_id'];
		}
		if ( ! empty( $f['actor'] ) ) {
			if ( in_array( $f['actor'], array( 'system', 'admin', 'member' ), true ) ) {
				$cond[] = 'actor_type = %s';
				$args[] = (string) $f['actor'];
			} else {
				$cond[] = 'actor_id = %d';
				$args[] = (int) $f['actor'];
			}
		}
		if ( ! empty( $f['action'] ) ) {
			global $wpdb;
			$cond[] = 'action LIKE %s';
			$args[] = $wpdb->esc_like( (string) $f['action'] ) . '%';
		}
		if ( ! empty( $f['from'] ) ) {
			$cond[] = 'created_at >= %s';
			$args[] = gmdate( 'Y-m-d 00:00:00', (int) strtotime( (string) $f['from'] ) );
		}
		if ( ! empty( $f['to'] ) ) {
			$cond[] = 'created_at <= %s';
			$args[] = gmdate( 'Y-m-d 23:59:59', (int) strtotime( (string) $f['to'] ) );
		}
		$res          = $this->repo->paginate( $cond, $args, $p );
		$res['items'] = array_map( array( $this, 'present' ), $res['items'] );
		return $res;
	}

	/**
	 * Audit rows for a member and their subscriptions/orders.
	 *
	 * @return array{items:list<array<string,mixed>>, meta:array<string,int>}
	 */
	public function forMember( int $memberId, Pagination $p ): array {
		$s    = Db::table( 'subscriptions' );
		$o    = Db::table( 'orders' );
		$cond = array( "((entity_type = 'member' AND entity_id = %d) OR (entity_type = 'subscription' AND entity_id IN (SELECT id FROM {$s} WHERE member_id = %d)) OR (entity_type = 'order' AND entity_id IN (SELECT id FROM {$o} WHERE member_id = %d)))" );
		$res  = $this->repo->paginate( $cond, array( $memberId, $memberId, $memberId ), $p );
		$res['items'] = array_map( array( $this, 'present' ), $res['items'] );
		return $res;
	}

	/** @param array<string,mixed> $f */
	public function csv( array $f ): string {
		$fh = fopen( 'php://temp', 'w+' );
		if ( ! $fh ) {
			return '';
		}
		fputcsv( $fh, array( 'ID', 'When (UTC)', 'Actor', 'Action', 'Entity', 'Entity ID', 'Reason', 'IP', 'Before', 'After' ) );
		$page = 1;
		do {
			$res = $this->list( $f, new Pagination( $page, 200 ) );
			foreach ( $res['items'] as $r ) {
				fputcsv( $fh, array( $r['id'], $r['created_at'], $r['actor_name'], $r['action'], $r['entity_type'], $r['entity_id'], $r['reason'], $r['ip'], wp_json_encode( $r['before'] ), wp_json_encode( $r['after'] ) ) );
			}
			++$page;
		} while ( $page <= $res['meta']['pages'] && $page < 100 );
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}

	/** @return list<array<string,mixed>> */
	public function recent( int $limit = 15 ): array {
		return array_map( array( $this, 'present' ), $this->repo->where( array(), 'id DESC', $limit ) );
	}

	/**
	 * @param array<string,mixed> $r
	 * @return array<string,mixed>
	 */
	public function present( array $r ): array {
		$user = $r['actor_id'] ? get_userdata( (int) $r['actor_id'] ) : null;
		return array(
			'id'          => (int) $r['id'],
			'actor_type'  => (string) $r['actor_type'],
			'actor_id'    => $r['actor_id'] ? (int) $r['actor_id'] : null,
			'actor_name'  => $user ? $user->display_name : ucfirst( (string) $r['actor_type'] ),
			'action'      => (string) $r['action'],
			'entity_type' => (string) $r['entity_type'],
			'entity_id'   => $r['entity_id'] ? (int) $r['entity_id'] : null,
			'before'      => $r['before_data'],
			'after'       => $r['after_data'],
			'reason'      => $r['reason'],
			'ip'          => $r['ip'],
			'created_at'  => Clock::iso( $r['created_at'] ),
		);
	}
}
