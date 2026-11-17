<?php
declare( strict_types=1 );

namespace WitsValley\Members\Support;

/**
 * Money helpers. All amounts are stored as integer minor units + ISO-4217 code.
 */
final class Money {

	/** @var array<string,int> Currency => decimals. */
	private const DECIMALS = array(
		'UGX' => 0,
		'KES' => 2,
		'TZS' => 2,
		'RWF' => 0,
		'USD' => 2,
		'EUR' => 2,
		'GBP' => 2,
		'NGN' => 2,
		'ZAR' => 2,
		'GHS' => 2,
	);

	/** @var array<string,string> */
	private const SYMBOLS = array(
		'UGX' => 'UGX',
		'KES' => 'KES',
		'TZS' => 'TZS',
		'RWF' => 'RWF',
		'USD' => '$',
		'EUR' => '€',
		'GBP' => '£',
		'NGN' => '₦',
		'ZAR' => 'R',
		'GHS' => 'GH₵',
	);

	public static function decimals( string $currency ): int {
		return self::DECIMALS[ strtoupper( $currency ) ] ?? 2;
	}

	public static function format( int $minor, string $currency ): string {
		$currency = strtoupper( $currency );
		$decimals = self::decimals( $currency );
		$major    = $minor / ( 10 ** $decimals );
		$number   = number_format( $major, $decimals, '.', ',' );
		$symbol   = self::SYMBOLS[ $currency ] ?? $currency;
		if ( strlen( $symbol ) > 1 && ctype_alpha( $symbol ) ) {
			return $symbol . ' ' . $number;
		}
		return $symbol . $number;
	}

	/** Convert a decimal major amount (e.g. Woo order total "20000.00") into minor units. */
	public static function toMinor( float|string $major, string $currency ): int {
		$decimals = self::decimals( $currency );
		return (int) round( (float) $major * ( 10 ** $decimals ) );
	}

	public static function toMajor( int $minor, string $currency ): float {
		return $minor / ( 10 ** self::decimals( $currency ) );
	}

	public static function isSupported( string $currency ): bool {
		return 1 === preg_match( '/^[A-Z]{3}$/', strtoupper( $currency ) );
	}

	/** @return list<string> */
	public static function known(): array {
		return array_keys( self::DECIMALS );
	}
}
