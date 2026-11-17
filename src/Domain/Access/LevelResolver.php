<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Access;

use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;

/**
 * Resolves a user's access level: max over active/past_due subscriptions and active organisation seats.
 * 0 = Public, 1 = Basic, 2 = Premium, 3 = Enterprise.
 */
final class LevelResolver {

	public const LEVELS = array(
		0 => 'Public',
		1 => 'Basic',
		2 => 'Premium',
		3 => 'Enterprise',
	);

	/** @var array<int,array<string,mixed>> */
	private array $cache = array();

	public function levelFor( int $userId ): int {
		return (int) $this->entitlements( $userId )['level'];
	}

	/**
	 * @return array{level:int, plan_slug:?string, plan_name:?string, plan_slugs:list<string>, org_id:?int, org_ids:list<int>, grants:list<string>, member_id:?int}
	 */
	public function entitlements( int $userId ): array {
		if ( isset( $this->cache[ $userId ] ) ) {
			return $this->cache[ $userId ];
		}
		$out = array(
			'level'      => 0,
			'plan_slug'  => null,
			'plan_name'  => null,
			'plan_slugs' => array(),
			'org_id'     => null,
			'org_ids'    => array(),
			'grants'     => array(),
			'member_id'  => null,
		);
		if ( $userId > 0 ) {
			global $wpdb;
			$m         = Db::table( 'members' );
			$s         = Db::table( 'subscriptions' );
			$p         = Db::table( 'plans' );
			$o         = Db::table( 'organisations' );
			$om        = Db::table( 'organisation_members' );
			$member_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$m} WHERE user_id = %d", $userId ) ); // phpcs:ignore WordPress.DB
			if ( $member_id ) {
				$out['member_id'] = $member_id;
				$rows             = $wpdb->get_results( $wpdb->prepare( "SELECT p.slug, p.name, p.level, s.organisation_id FROM {$s} s INNER JOIN {$p} p ON p.id = s.plan_id WHERE s.member_id = %d AND s.status IN ('active','past_due') ORDER BY p.level DESC", $member_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
				foreach ( $rows ?: array() as $row ) {
					$out['plan_slugs'][] = (string) $row['slug'];
					if ( (int) $row['level'] > $out['level'] || null === $out['plan_slug'] ) {
						$out['level']     = max( $out['level'], (int) $row['level'] );
						$out['plan_slug'] = $out['plan_slug'] ?? (string) $row['slug'];
						$out['plan_name'] = $out['plan_name'] ?? (string) $row['name'];
					}
				}
				$today = gmdate( 'Y-m-d', Clock::now()->getTimestamp() );
				$orgs  = $wpdb->get_results( $wpdb->prepare( "SELECT o.id, o.level FROM {$om} om INNER JOIN {$o} o ON o.id = om.organisation_id WHERE om.member_id = %d AND om.joined_at IS NOT NULL AND om.revoked_at IS NULL AND o.status = 'active' AND (o.term_end IS NULL OR o.term_end >= %s)", $member_id, $today ), ARRAY_A ); // phpcs:ignore WordPress.DB
				foreach ( $orgs ?: array() as $org ) {
					$out['org_ids'][] = (int) $org['id'];
					$out['org_id']    = $out['org_id'] ?? (int) $org['id'];
					$out['level']     = max( $out['level'], (int) $org['level'] );
				}
			}
			$out['grants'] = array_values( array_unique( $out['plan_slugs'] ) );
		}
		$out['level']           = (int) apply_filters( 'wvm_user_level', $out['level'], $userId );
		$this->cache[ $userId ] = $out;
		return $out;
	}

	public function flush(): void {
		$this->cache = array();
	}

	public static function label( int $level ): string {
		return self::LEVELS[ $level ] ?? 'Level ' . $level;
	}
}
