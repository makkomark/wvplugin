<?php
declare( strict_types=1 );

namespace WitsValley\Members\Install;

/**
 * Versioned data migrations. Schema changes go in Schema (dbDelta is additive);
 * data transforms go here, keyed by the version that introduced them.
 */
final class Migrations {

	/** @return array<string,callable():void> */
	public static function all(): array {
		return array(
			'1.0.0' => static function (): void {
				// Initial release: nothing to transform.
			},
		);
	}

	public static function run( string $from ): void {
		foreach ( self::all() as $version => $migration ) {
			if ( version_compare( $from, $version, '<' ) ) {
				$migration();
			}
		}
	}
}
