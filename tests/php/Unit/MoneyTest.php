<?php
namespace WitsValley\Members\Tests\Unit;

use WitsValley\Members\Domain\Subscription\Periods;
use WitsValley\Members\Support\Money;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class MoneyTest extends TestCase {

	public function test_ugx_has_zero_decimals_and_usd_two(): void {
		$this->assertSame( 0, Money::decimals( 'UGX' ) );
		$this->assertSame( 2, Money::decimals( 'USD' ) );
		$this->assertSame( 'UGX 20,000', Money::format( 20000, 'UGX' ) );
		$this->assertSame( '$6.00', Money::format( 600, 'USD' ) );
	}

	public function test_minor_conversion(): void {
		$this->assertSame( 20000, Money::toMinor( '20000.00', 'UGX' ) );
		$this->assertSame( 1500, Money::toMinor( 15, 'USD' ) );
		$this->assertSame( 1999, Money::toMinor( '19.99', 'USD' ) );
		$this->assertSame( 6.0, Money::toMajor( 600, 'USD' ) );
	}

	public function test_month_arithmetic_clamps_to_month_end(): void {
		$jan31 = new \DateTimeImmutable( '2026-01-31 10:00:00', new \DateTimeZone( 'UTC' ) );
		$this->assertSame( '2026-02-28', Periods::addMonths( $jan31, 1 )->format( 'Y-m-d' ) );
		$this->assertSame( '2026-03-31', Periods::addMonths( $jan31, 2 )->format( 'Y-m-d' ) );
		$this->assertSame( '2027-01-31', Periods::addMonths( $jan31, 12 )->format( 'Y-m-d' ) );
	}
}
