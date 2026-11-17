<?php
declare( strict_types=1 );

namespace WitsValley\Members\Woo;

/**
 * Keeps one hidden, virtual WooCommerce product per paid plan.
 */
final class ProductSync {

	public const META_PLAN = '_wvm_plan_id';

	public function register(): void {
		add_filter( 'woocommerce_is_purchasable', array( $this, 'purchasable' ), 10, 2 );
		add_filter( 'woocommerce_product_query_meta_query', array( $this, 'hideFromQueries' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'hideFromSitemap' ), 10, 2 );
	}

	/**
	 * Create or update the product for a plan. Returns product id (null for free plans).
	 *
	 * @param array<string,mixed> $plan Presented plan.
	 */
	public function sync( array $plan ): ?int {
		if ( ! function_exists( 'wc_get_product' ) || ! empty( $plan['is_free'] ) ) {
			return null;
		}
		$product = null;
		if ( ! empty( $plan['woo_product_id'] ) ) {
			$product = wc_get_product( (int) $plan['woo_product_id'] );
		}
		if ( ! $product ) {
			$product = new \WC_Product_Simple();
		}
		$store_currency = get_woocommerce_currency();
		$prices         = (array) ( $plan['prices'] ?? array() );
		$amount         = $prices[ $store_currency ] ?? ( $prices ? reset( $prices ) : 0 );
		$currency       = isset( $prices[ $store_currency ] ) ? $store_currency : (string) ( array_key_first( $prices ) ?? $store_currency );

		$product->set_name( 'WitsValley ' . $plan['name'] . ' membership' );
		$product->set_slug( 'wvm-plan-' . $plan['slug'] );
		$product->set_status( empty( $plan['archived_at'] ) ? 'publish' : 'private' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_tax_status( 'none' );
		$product->set_regular_price( (string) \WitsValley\Members\Support\Money::toMajor( (int) $amount, $currency ) );
		$product->set_short_description( (string) ( $plan['description'] ?? '' ) );
		$product->update_meta_data( self::META_PLAN, (int) $plan['id'] );
		return (int) $product->save();
	}

	/** Plan products cannot be added to a cart; plugin orders are created server-side. */
	public function purchasable( bool $purchasable, \WC_Product $product ): bool {
		if ( $product->get_meta( self::META_PLAN ) ) {
			return false;
		}
		return $purchasable;
	}

	/**
	 * @param array<int|string,mixed> $meta
	 * @return array<int|string,mixed>
	 */
	public function hideFromQueries( array $meta ): array {
		$meta[] = array(
			'key'     => self::META_PLAN,
			'compare' => 'NOT EXISTS',
		);
		return $meta;
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function hideFromSitemap( array $args, string $postType ): array {
		if ( 'product' === $postType ) {
			$args['meta_query']   = $args['meta_query'] ?? array(); // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['meta_query'][] = array(
				'key'     => self::META_PLAN,
				'compare' => 'NOT EXISTS',
			);
		}
		return $args;
	}
}
