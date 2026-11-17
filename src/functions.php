<?php
/**
 * Global helpers.
 *
 * @package WitsValley\Members
 */

declare( strict_types=1 );

use WitsValley\Members\Plugin;

if ( ! function_exists( 'wvm' ) ) {
	/**
	 * Resolve a service from the plugin container.
	 *
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	function wvm( string $id ): object {
		return Plugin::instance()->container()->get( $id );
	}
}
