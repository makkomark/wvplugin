<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Kit;

use WitsValley\Members\Domain\Plan\PlanRepository;
use WitsValley\Members\Support\Settings;

/**
 * Settings-driven map: member state → {add[], remove[]}. Tokens:
 *   {plan_tag}        tag of the member's current plan
 *   {plan_tags}       all plan tags
 *   {other_plan_tags} plan tags except the current plan's
 * Only WV-* tags are ever touched.
 */
final class TagMap {

	public const STATES = array( 'free', 'active', 'past_due', 'suspended', 'expired', 'cancelled' );

	public const PREFIX = 'WV-';

	public function __construct( private Settings $settings, private PlanRepository $plans ) {}

	/** @return array<string,array{add:list<string>,remove:list<string>}> */
	public static function defaults(): array {
		return array(
			'free'      => array(
				'add'    => array( 'WV-FREE' ),
				'remove' => array(),
			),
			'active'    => array(
				'add'    => array( '{plan_tag}' ),
				'remove' => array( 'WV-FREE', 'WV-EXPIRED', 'WV-CANCELLED', 'WV-PAST-DUE', '{other_plan_tags}' ),
			),
			'past_due'  => array(
				'add'    => array( '{plan_tag}', 'WV-PAST-DUE' ),
				'remove' => array(),
			),
			'suspended' => array(
				'add'    => array( 'WV-EXPIRED' ),
				'remove' => array( '{plan_tags}', 'WV-PAST-DUE' ),
			),
			'expired'   => array(
				'add'    => array( 'WV-EXPIRED' ),
				'remove' => array( '{plan_tags}', 'WV-PAST-DUE' ),
			),
			'cancelled' => array(
				'add'    => array( 'WV-CANCELLED' ),
				'remove' => array( '{plan_tags}', 'WV-PAST-DUE' ),
			),
		);
	}

	/** @return array<string,array{add:list<string>,remove:list<string>}> */
	public function map(): array {
		$stored = (array) $this->settings->get( 'kit', 'tag_map', array() );
		$map    = self::defaults();
		foreach ( self::STATES as $state ) {
			if ( isset( $stored[ $state ] ) && is_array( $stored[ $state ] ) ) {
				$map[ $state ] = array(
					'add'    => array_values( array_map( 'strval', (array) ( $stored[ $state ]['add'] ?? array() ) ) ),
					'remove' => array_values( array_map( 'strval', (array) ( $stored[ $state ]['remove'] ?? array() ) ) ),
				);
			}
		}
		return $map;
	}

	/** @return list<string> */
	public function planTags(): array {
		$tags = array();
		foreach ( $this->plans->all( true ) as $plan ) {
			if ( ! empty( $plan['kit_tag'] ) && ! $plan['is_free'] ) {
				$tags[] = (string) $plan['kit_tag'];
			}
		}
		return array_values( array_unique( $tags ) );
	}

	/**
	 * Resolved add/remove for a state.
	 *
	 * @param list<string> $extraAdd Additional tags (e.g. enterprise seat tag).
	 * @return array{add:list<string>,remove:list<string>}
	 */
	public function resolve( string $state, ?string $planTag, array $extraAdd = array() ): array {
		$rule     = $this->map()[ $state ] ?? array(
			'add'    => array(),
			'remove' => array(),
		);
		$planTags = $this->planTags();
		$expand   = function ( array $list ) use ( $planTag, $planTags ): array {
			$out = array();
			foreach ( $list as $tag ) {
				if ( '{plan_tag}' === $tag ) {
					if ( $planTag ) {
						$out[] = $planTag;
					}
				} elseif ( '{plan_tags}' === $tag ) {
					$out = array_merge( $out, $planTags );
				} elseif ( '{other_plan_tags}' === $tag ) {
					$out = array_merge( $out, array_diff( $planTags, array( (string) $planTag ) ) );
				} else {
					$out[] = $tag;
				}
			}
			return array_values( array_unique( array_filter( $out, array( self::class, 'isOwned' ) ) ) );
		};
		$add      = array_values( array_unique( array_merge( $expand( $rule['add'] ), array_filter( $extraAdd, array( self::class, 'isOwned' ) ) ) ) );
		$remove   = array_values( array_diff( $expand( $rule['remove'] ), $add ) );
		return array(
			'add'    => $add,
			'remove' => $remove,
		);
	}

	/**
	 * Expected WV-* tags given the current ones.
	 *
	 * @param list<string> $current
	 * @param array{add:list<string>,remove:list<string>} $rule
	 * @return list<string>
	 */
	public static function apply( array $current, array $rule ): array {
		$owned = array_filter( $current, array( self::class, 'isOwned' ) );
		$next  = array_diff( $owned, $rule['remove'] );
		$next  = array_unique( array_merge( $next, $rule['add'] ) );
		sort( $next );
		return array_values( $next );
	}

	public static function isOwned( string $tag ): bool {
		return 0 === strpos( strtoupper( $tag ), self::PREFIX );
	}
}
