<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Plan;

use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Money;
use WitsValley\Members\Support\Validation;
use WitsValley\Members\Woo\ProductSync;

/**
 * Plans: CRUD, prices, ordering, Woo product sync.
 */
final class PlanService {

	public const INTERVALS = array( 'none', 'monthly', 'quarterly', 'annual', 'one_off', 'custom', 'contract' );

	public const VISIBILITY = array( 'public', 'hidden', 'invite' );

	private const RULES = array(
		'slug'           => 'slug',
		'name'           => 'maxlen:100',
		'tagline'        => 'maxlen:200',
		'description'    => 'text',
		'level'          => 'int|min:0|max:9',
		'interval'       => 'in:none,monthly,quarterly,annual,one_off,custom,contract',
		'interval_count' => 'int|min:1|max:3650',
		'trial_days'     => 'int|min:0|max:365',
		'kit_tag'        => 'maxlen:100',
		'visibility'     => 'in:public,hidden,invite',
		'benefits'       => 'array',
		'is_free'        => 'bool',
		'is_quoted'      => 'bool',
		'highlight'      => 'bool',
		'prices'         => 'array',
	);

	/** Fields the Content role may change (benefits/levels only). */
	public const CONTENT_FIELDS = array( 'benefits', 'level', 'tagline', 'description' );

	public function __construct(
		private PlanRepository $plans,
		private PlanPriceRepository $prices,
		private Audit $audit,
		private ProductSync $productSync
	) {}

	/** @return array<string,mixed> */
	public function get( int $id ): array {
		return $this->present( $this->plans->findOrFail( $id, 'plan' ) );
	}

	/** @return array<string,mixed>|null */
	public function findBySlug( string $slug ): ?array {
		$row = $this->plans->findBy( 'slug', $slug );
		return $row ? $this->present( $row ) : null;
	}

	/** @return list<array<string,mixed>> */
	public function list( bool $includeArchived = false ): array {
		$counts = $this->memberCounts();
		return array_map(
			function ( array $p ) use ( $counts ): array {
				$out                 = $this->present( $p );
				$out['member_count'] = $counts[ (int) $p['id'] ] ?? 0;
				return $out;
			},
			$this->plans->all( $includeArchived )
		);
	}

	/**
	 * @param array<string,mixed> $dto
	 * @return array<string,mixed>
	 */
	public function create( array $dto ): array {
		$data = Validation::validate(
			$dto,
			array_merge(
				self::RULES,
				array(
					'slug'  => 'required|slug',
					'name'  => 'required|maxlen:100',
					'level' => 'required|int|min:0|max:9',
				)
			)
		);
		if ( $this->plans->findBy( 'slug', $data['slug'] ) ) {
			throw DomainError::validation( array( 'slug' => 'A plan with this slug already exists.' ) );
		}
		$is_free = ! empty( $data['is_free'] );
		if ( $is_free && $this->plans->free() ) {
			throw DomainError::validation( array( 'is_free' => 'There can only be one free plan.' ) );
		}
		$id   = Db::transaction(
			function () use ( $data, $is_free ): int {
				$id = $this->plans->insert(
					array(
						'slug'           => $data['slug'],
						'name'           => $data['name'],
						'tagline'        => $data['tagline'] ?? '',
						'description'    => $data['description'] ?? '',
						'level'          => (int) $data['level'],
						'interval_unit'  => $is_free ? 'none' : ( $data['interval'] ?? 'monthly' ),
						'interval_count' => (int) ( $data['interval_count'] ?? 1 ),
						'trial_days'     => (int) ( $data['trial_days'] ?? 0 ),
						'kit_tag'        => $data['kit_tag'] ?? 'WV-' . strtoupper( (string) $data['slug'] ),
						'visibility'     => $data['visibility'] ?? 'public',
						'benefits'       => $this->cleanBenefits( $data['benefits'] ?? array() ),
						'sort_order'     => $this->plans->count() + 1,
						'is_free'        => $is_free,
						'is_quoted'      => ! empty( $data['is_quoted'] ),
						'highlight'      => ! empty( $data['highlight'] ),
					)
				);
				if ( ! $is_free ) {
					foreach ( (array) ( $data['prices'] ?? array() ) as $price ) {
						$this->writePrice( $id, (array) $price );
					}
				}
				return $id;
			}
		);
		$plan = $this->plans->findOrFail( $id );
		$this->audit->log( 'plan.created', 'plan', $id, null, $this->present( $plan ) );
		$this->sync( $id );
		return $this->get( $id );
	}

	/**
	 * @param array<string,mixed> $dto
	 * @return array<string,mixed>
	 */
	public function update( int $id, array $dto, bool $contentOnly = false ): array {
		$before = $this->get( $id );
		if ( array_key_exists( 'slug', $dto ) && $dto['slug'] !== $before['slug'] ) {
			throw DomainError::validation( array( 'slug' => 'The slug cannot be changed after creation.' ) );
		}
		unset( $dto['slug'] );
		if ( $contentOnly ) {
			$dto = array_intersect_key( $dto, array_flip( self::CONTENT_FIELDS ) );
		}
		$data = Validation::validate( $dto, self::RULES );
		if ( array_key_exists( 'is_free', $data ) && (bool) $data['is_free'] !== (bool) $before['is_free'] ) {
			throw DomainError::validation( array( 'is_free' => 'The free flag cannot be changed after creation.' ) );
		}
		$row = array();
		foreach ( array( 'name', 'tagline', 'description', 'level', 'interval_count', 'trial_days', 'kit_tag', 'visibility', 'is_quoted', 'highlight' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$row[ $key ] = $data[ $key ];
			}
		}
		if ( array_key_exists( 'interval', $data ) && ! $before['is_free'] ) {
			$row['interval_unit'] = $data['interval'];
		}
		if ( array_key_exists( 'benefits', $data ) ) {
			$row['benefits'] = $this->cleanBenefits( (array) $data['benefits'] );
		}
		Db::transaction(
			function () use ( $id, $row, $data, $before ): void {
				if ( $row ) {
					$this->plans->update( $id, $row );
				}
				if ( isset( $data['prices'] ) && is_array( $data['prices'] ) && ! $before['is_free'] ) {
					$seen = array();
					foreach ( $data['prices'] as $price ) {
						$seen[] = $this->writePrice( $id, (array) $price );
					}
					foreach ( array_keys( $before['prices'] ) as $currency ) {
						if ( ! in_array( $currency, $seen, true ) ) {
							$this->prices->remove( $id, (string) $currency );
						}
					}
				}
			}
		);
		$after = $this->get( $id );
		$this->audit->log( 'plan.updated', 'plan', $id, $before, $after );
		$this->sync( $id );
		$after['price_changed'] = $after['prices'] !== $before['prices'];
		return $after;
	}

	/** @return array<string,mixed> */
	public function archive( int $id ): array {
		$before = $this->get( $id );
		if ( $before['archived_at'] ) {
			return $before;
		}
		$this->plans->update( $id, array( 'archived_at' => Clock::mysql() ) );
		$after = $this->get( $id );
		$this->audit->log( 'plan.archived', 'plan', $id, $before, $after );
		$this->sync( $id );
		return $after;
	}

	/** @return array<string,mixed> */
	public function restore( int $id ): array {
		$before = $this->get( $id );
		if ( $before['is_free'] && $this->plans->free() ) {
			throw DomainError::conflict( 'Another free plan is active.' );
		}
		$this->plans->update( $id, array( 'archived_at' => null ) );
		$after = $this->get( $id );
		$this->audit->log( 'plan.restored', 'plan', $id, $before, $after );
		$this->sync( $id );
		return $after;
	}

	/** @param list<int> $ids */
	public function reorder( array $ids ): void {
		$before = array_map( static fn( $p ) => $p['id'], $this->plans->all( true ) );
		Db::transaction(
			function () use ( $ids ): void {
				foreach ( array_values( $ids ) as $i => $id ) {
					$this->plans->update( (int) $id, array( 'sort_order' => $i + 1 ) );
				}
			}
		);
		$this->audit->log( 'plan.reordered', 'plan', null, array( 'order' => $before ), array( 'order' => array_map( 'intval', $ids ) ) );
	}

	/** @return array<string,mixed> */
	public function setPrice( int $planId, string $currency, int $amountMinor ): array {
		$before = $this->get( $planId );
		if ( $before['is_free'] ) {
			throw DomainError::validation( array( 'currency' => 'The free plan has no prices.' ) );
		}
		$this->writePrice(
			$planId,
			array(
				'currency'     => $currency,
				'amount_minor' => $amountMinor,
			)
		);
		$after = $this->get( $planId );
		$this->audit->log( 'plan.price_set', 'plan', $planId, array( 'prices' => $before['prices'] ), array( 'prices' => $after['prices'] ) );
		$this->sync( $planId );
		return $after;
	}

	/** @return array<string,mixed> */
	public function removePrice( int $planId, string $currency ): array {
		$before = $this->get( $planId );
		$this->prices->remove( $planId, $currency );
		$after = $this->get( $planId );
		$this->audit->log( 'plan.price_removed', 'plan', $planId, array( 'prices' => $before['prices'] ), array( 'prices' => $after['prices'] ) );
		$this->sync( $planId );
		return $after;
	}

	/**
	 * Public plan list with price in one currency.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function getPublic( string $currency ): array {
		$currency = strtoupper( $currency );
		$out      = array();
		foreach ( $this->plans->all() as $plan ) {
			if ( 'public' !== $plan['visibility'] ) {
				continue;
			}
			$p      = $this->present( $plan );
			$amount = $p['prices'][ $currency ] ?? null;
			$out[]  = array(
				'id'             => $p['id'],
				'slug'           => $p['slug'],
				'name'           => $p['name'],
				'tagline'        => $p['tagline'],
				'description'    => $p['description'],
				'level'          => $p['level'],
				'interval'       => $p['interval'],
				'interval_count' => $p['interval_count'],
				'trial_days'     => $p['trial_days'],
				'benefits'       => $p['benefits'],
				'is_free'        => $p['is_free'],
				'is_quoted'      => $p['is_quoted'],
				'highlight'      => $p['highlight'],
				'currency'       => $currency,
				'amount_minor'   => $p['is_free'] ? 0 : $amount,
				'amount_display' => $p['is_free'] ? 'Free' : ( null !== $amount ? Money::format( (int) $amount, $currency ) : null ),
				'available'      => $p['is_free'] || $p['is_quoted'] || null !== $amount,
				'prices'         => $p['prices'],
			);
		}
		return $out;
	}

	public function syncWooProduct( int $planId ): ?int {
		return $this->sync( $planId );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public function present( array $row ): array {
		$prices = $this->prices->forPlan( (int) $row['id'] );
		return array(
			'id'              => (int) $row['id'],
			'slug'            => (string) $row['slug'],
			'name'            => (string) $row['name'],
			'tagline'         => (string) ( $row['tagline'] ?? '' ),
			'description'     => (string) ( $row['description'] ?? '' ),
			'level'           => (int) $row['level'],
			'interval'        => (string) $row['interval_unit'],
			'interval_count'  => (int) $row['interval_count'],
			'trial_days'      => (int) $row['trial_days'],
			'kit_tag'         => (string) ( $row['kit_tag'] ?? '' ),
			'visibility'      => (string) $row['visibility'],
			'benefits'        => is_array( $row['benefits'] ) ? $row['benefits'] : array(),
			'sort_order'      => (int) $row['sort_order'],
			'woo_product_id'  => $row['woo_product_id'] ? (int) $row['woo_product_id'] : null,
			'woo_product_url' => $row['woo_product_id'] ? admin_url( 'post.php?post=' . (int) $row['woo_product_id'] . '&action=edit' ) : null,
			'is_free'         => (bool) $row['is_free'],
			'is_quoted'       => (bool) $row['is_quoted'],
			'highlight'       => (bool) $row['highlight'],
			'archived_at'     => Clock::iso( $row['archived_at'] ?? null ),
			'prices'          => $prices,
			'prices_display'  => array_map( static fn( $amt, $cur ) => Money::format( (int) $amt, (string) $cur ), $prices, array_keys( $prices ) ),
			'created_at'      => Clock::iso( $row['created_at'] ?? null ),
			'updated_at'      => Clock::iso( $row['updated_at'] ?? null ),
		);
	}

	/** @param array<string,mixed> $price */
	private function writePrice( int $planId, array $price ): string {
		$currency = strtoupper( (string) ( $price['currency'] ?? '' ) );
		if ( ! Money::isSupported( $currency ) ) {
			throw DomainError::validation( array( 'prices' => 'Invalid currency ' . $currency ) );
		}
		$amount = (int) ( $price['amount_minor'] ?? -1 );
		if ( $amount < 0 ) {
			throw DomainError::validation( array( 'prices' => 'Price must be zero or more.' ) );
		}
		$this->prices->upsert( $planId, $currency, $amount );
		return $currency;
	}

	/**
	 * @param array<int|string,mixed> $benefits
	 * @return list<string>
	 */
	private function cleanBenefits( array $benefits ): array {
		$out = array();
		foreach ( $benefits as $b ) {
			$b = trim( sanitize_text_field( is_array( $b ) ? (string) ( $b['text'] ?? '' ) : (string) $b ) );
			if ( '' !== $b ) {
				$out[] = $b;
			}
		}
		return $out;
	}

	private function sync( int $planId ): ?int {
		$plan = $this->plans->findOrFail( $planId );
		$id   = $this->productSync->sync( $this->present( $plan ) );
		if ( $id && (int) $plan['woo_product_id'] !== $id ) {
			$this->plans->update( $planId, array( 'woo_product_id' => $id ) );
		}
		return $id;
	}

	/** @return array<int,int> plan_id => active member count */
	private function memberCounts(): array {
		global $wpdb;
		$t    = Db::table( 'subscriptions' );
		$rows = $wpdb->get_results( "SELECT plan_id, COUNT(DISTINCT member_id) c FROM {$t} WHERE status IN ('active','past_due') GROUP BY plan_id", ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( $rows ?: array() as $r ) {
			$out[ (int) $r['plan_id'] ] = (int) $r['c'];
		}
		return $out;
	}
}
