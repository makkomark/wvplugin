<?php
namespace WitsValley\Members\Tests\Integration;

use WitsValley\Members\Domain\Notification\Urls;
use WitsValley\Members\Support\Settings;
use WitsValley\Members\Tests\TestCase;
use WitsValley\Members\Woo\ProductSync;
use WitsValley\Members\Woo\Redirects;

final class RedirectCaught extends \RuntimeException {}

/** Membership-only site (default) vs "Keep the WooCommerce shop". */
final class WooShopTest extends TestCase {

	private int $book;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$product = new \WC_Product_Simple();
		$product->set_name( 'The WitsValley book' );
		$product->set_regular_price( '20' );
		$product->set_status( 'publish' );
		$this->book = (int) $product->save();
		add_filter( 'wp_redirect', array( $this, 'catchRedirect' ), 1 );
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', array( $this, 'catchRedirect' ), 1 );
		wvm( Settings::class )->set( 'payments', 'woo_shop', false );
		parent::tear_down();
	}

	public function catchRedirect( string $location ): string {
		throw new RedirectCaught( $location );
	}

	private function redirectFor( string $url ): ?string {
		$this->go_to( $url );
		try {
			wvm( Redirects::class )->redirect();
		} catch ( RedirectCaught $e ) {
			return $e->getMessage();
		}
		return null;
	}

	public function test_membership_only_site_sends_products_to_the_membership_page(): void {
		$this->assertSame( Urls::pricing(), $this->redirectFor( get_permalink( $this->book ) ) );
	}

	public function test_with_the_shop_kept_normal_products_open_and_plan_products_still_redirect(): void {
		wvm( Settings::class )->set( 'payments', 'woo_shop', true );
		$this->assertNull( $this->redirectFor( get_permalink( $this->book ) ), 'A normal product page opens.' );

		$plan = new \WC_Product_Simple();
		$plan->set_name( 'WitsValley Basic membership' );
		$plan->set_status( 'publish' );
		$plan->set_catalog_visibility( 'hidden' );
		$plan->update_meta_data( ProductSync::META_PLAN, (int) $this->plan( 'basic' )['id'] );
		$planProduct = (int) $plan->save();
		$this->assertGreaterThan( 0, $planProduct );
		$this->assertSame( Urls::pricing(), $this->redirectFor( get_permalink( $planProduct ) ), 'Membership products still open the membership page.' );

		$redirects = wvm( Redirects::class );
		$this->assertSame( 'https://example.org/shop/', $redirects->returnToShop( 'https://example.org/shop/' ) );
		$this->assertSame( 'https://example.org/my-account/', $redirects->accountPermalink( 'https://example.org/my-account/' ) );
	}
}
