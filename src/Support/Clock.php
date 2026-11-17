<?php
declare( strict_types=1 );

namespace WitsValley\Members\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * UTC clock that can be frozen in tests.
 */
final class Clock {

	private static ?DateTimeImmutable $frozen = null;

	public static function now(): DateTimeImmutable {
		return self::$frozen ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	public static function freeze( DateTimeImmutable|string $at ): void {
		self::$frozen = is_string( $at ) ? new DateTimeImmutable( $at, new DateTimeZone( 'UTC' ) ) : $at->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	public static function travel( string $modifier ): void {
		self::$frozen = self::now()->modify( $modifier );
	}

	public static function unfreeze(): void {
		self::$frozen = null;
	}

	/** MySQL DATETIME string in UTC. */
	public static function mysql( ?DateTimeImmutable $at = null ): string {
		return ( $at ?? self::now() )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	public static function parse( ?string $value ): ?DateTimeImmutable {
		if ( null === $value || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}

	/** ISO-8601 (UTC, Z) for API output. */
	public static function iso( ?string $mysql ): ?string {
		$dt = self::parse( $mysql );
		return $dt ? $dt->format( 'Y-m-d\TH:i:s\Z' ) : null;
	}

	public static function timestamp( ?string $mysql ): ?int {
		$dt = self::parse( $mysql );
		return $dt ? $dt->getTimestamp() : null;
	}

	public static function daysBetween( DateTimeImmutable $from, DateTimeImmutable $to ): int {
		return (int) floor( ( $to->getTimestamp() - $from->getTimestamp() ) / DAY_IN_SECONDS );
	}

	public static function inZone( ?string $mysql, string $tz, string $format = 'j M Y' ): string {
		$dt = self::parse( $mysql );
		if ( ! $dt ) {
			return '';
		}
		try {
			$zone = new DateTimeZone( $tz ?: 'Africa/Kampala' );
		} catch ( \Exception $e ) {
			$zone = new DateTimeZone( 'Africa/Kampala' );
		}
		return $dt->setTimezone( $zone )->format( $format );
	}
}
