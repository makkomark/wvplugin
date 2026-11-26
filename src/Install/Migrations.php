<?php
declare( strict_types=1 );

namespace WitsValley\Members\Install;

/**
 * Versioned data migrations. Schema changes go in Schema (dbDelta is additive);
 * data transforms go here, keyed by the version that introduced them.
 */
final class Migrations {

	/** @return array<string,callable> */
	public static function all(): array {
		return array(
			'1.0.0' => static function (): void {
				// Initial release: nothing to transform.
			},
			'1.0.2' => array( self::class, 'planCopy2026' ),
		);
	}

	/**
	 * New WitsValley plan copy (tagline, description, benefits) for Free, Basic and Premium.
	 * Only plans still carrying the original seeded wording are updated, so admin edits are never overwritten.
	 */
	public static function planCopy2026(): void {
		$old   = array(
			'free'    => 'Start learning with our free newsletter and selected lessons.',
			'basic'   => 'Core lessons and monthly live sessions.',
			'premium' => 'Everything in Basic plus deep-dive programmes and 1:1 consultations.',
		);
		$repo  = wvm( \WitsValley\Members\Domain\Plan\PlanRepository::class );
		$fresh = array();
		foreach ( Seeder::launchPlans() as $plan ) {
			$fresh[ $plan['slug'] ] = $plan;
		}
		foreach ( $old as $slug => $description ) {
			$row = $repo->findBy( 'slug', $slug );
			if ( ! $row || trim( (string) $row['description'] ) !== $description || ! isset( $fresh[ $slug ] ) ) {
				continue;
			}
			$repo->update(
				(int) $row['id'],
				array(
					'tagline'     => $fresh[ $slug ]['tagline'],
					'description' => $fresh[ $slug ]['description'],
					'benefits'    => $fresh[ $slug ]['benefits'],
				)
			);
			wvm( \WitsValley\Members\Domain\Audit\Audit::class )->log( 'plan.copy_updated', 'plan', (int) $row['id'], array( 'description' => $description ), array( 'tagline' => $fresh[ $slug ]['tagline'] ), 'Release 1.0.2 plan copy' );
		}
	}

	public static function run( string $from ): void {
		foreach ( self::all() as $version => $migration ) {
			if ( version_compare( $from, $version, '<' ) ) {
				$migration();
			}
		}
	}
}
