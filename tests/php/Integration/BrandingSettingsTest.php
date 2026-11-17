<?php
namespace WitsValley\Members\Tests\Integration;

use WitsValley\Members\Support\Branding;
use WitsValley\Members\Support\Settings;
use WitsValley\Members\Tests\TestCase;

final class BrandingSettingsTest extends TestCase {

	private function patch( array $body ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'PATCH', '/wv-members/v1/settings/branding' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
		return rest_do_request( $req );
	}

	public function test_defaults_are_witsvalley_brand(): void {
		$css = wvm( Branding::class )->css();
		$this->assertStringContainsString( '--wvm-primary:#0B4C25', $css );
		$this->assertStringContainsString( '--wvm-accent:#FFAC04', $css );
		$this->assertStringContainsString( 'prefers-color-scheme: dark', $css );
		$this->assertStringNotContainsStringIgnoringCase( '5b3df5', $css );
	}

	public function test_font_sources(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame(
			200,
			$this->patch(
				array(
					'font_source'  => 'google',
					'body_font'    => 'Inter',
					'heading_font' => 'Poppins',
				)
			)->get_status()
		);
		wvm( Settings::class )->flush();
		$fonts = wvm( Branding::class )->fonts();
		$this->assertStringStartsWith( '"Inter"', $fonts['body'] );
		$this->assertStringStartsWith( '"Poppins"', $fonts['heading'] );
		$this->assertStringContainsString( 'fonts.googleapis.com/css2?family=Inter:wght@', (string) $fonts['stylesheet'] );
		$this->assertStringContainsString( 'family=Poppins', (string) $fonts['stylesheet'] );

		$this->patch(
			array(
				'font_source'  => 'adobe',
				'adobe_kit_id' => 'abc1defg',
				'body_font'    => 'proxima-nova',
				'heading_font' => '',
			)
		);
		wvm( Settings::class )->flush();
		$fonts = wvm( Branding::class )->fonts();
		$this->assertSame( 'https://use.typekit.net/abc1defg.css', $fonts['stylesheet'] );
		$this->assertSame( $fonts['body'], $fonts['heading'], 'Heading falls back to the body font.' );

		$this->patch( array( 'admin_fonts' => 'system' ) );
		wvm( Settings::class )->flush();
		$this->assertNull( wvm( Branding::class )->fonts( true )['stylesheet'], 'wp-admin can stay on system fonts.' );
	}

	public function test_invalid_values_rejected_and_colours_change_everything(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 422, $this->patch( array( 'primary_color' => 'green' ) )->get_status() );
		$this->assertSame( 422, $this->patch( array( 'font_source' => 'comic' ) )->get_status() );
		$this->assertSame( 200, $this->patch( array( 'primary_color' => '#123abc' ) )->get_status() );
		wvm( Settings::class )->flush();
		$this->assertStringContainsString( '--wvm-primary:#123ABC', wvm( Branding::class )->css() );
		$html = wvm( \WitsValley\Members\Domain\Notification\Renderer::class )->layout( '<p>Hi</p>' );
		$this->assertStringContainsString( '#123ABC', $html, 'Emails follow the brand colour.' );
	}
}
