<?php
namespace WitsValley\Members\Tests\Unit;

use WitsValley\Members\Support\Branding;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class BrandingTest extends TestCase {

	public function test_hex_normalisation(): void {
		$this->assertSame( '#0B4C25', Branding::hex( '0b4c25', '#000000' ) );
		$this->assertSame( '#FFAA00', Branding::hex( '#fa0', '#000000' ) );
		$this->assertSame( '#123456', Branding::hex( 'not-a-colour', '#123456' ) );
	}

	public function test_mixing_and_contrast(): void {
		$this->assertSame( '#FFFFFF', Branding::mix( '#0B4C25', '#FFFFFF', 1.0 ) );
		$this->assertSame( '#0B4C25', Branding::mix( '#0B4C25', '#FFFFFF', 0.0 ) );
		$this->assertSame( '#FFFFFF', Branding::onColor( '#0B4C25' ), 'White text on WitsValley green.' );
		$this->assertSame( '#0E1F15', Branding::onColor( '#FFAC04' ), 'Dark text on WitsValley amber.' );
		$this->assertGreaterThan( 4.5, Branding::contrast( '#0B4C25', '#FFFFFF' ) );
	}
}
