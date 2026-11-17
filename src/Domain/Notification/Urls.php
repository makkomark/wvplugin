<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Notification;

use WitsValley\Members\Support\Settings;

/**
 * Canonical front-end URLs (dashboard routes, pricing, enterprise).
 */
final class Urls {

	public static function dashboard( string $route = '' ): string {
		$id   = (int) wvm( Settings::class )->get( 'general', 'dashboard_page_id', 0 );
		$base = $id && get_post( $id ) ? (string) get_permalink( $id ) : home_url( '/dashboard/' );
		return $route ? trailingslashit( $base ) . '#/' . ltrim( $route, '/' ) : $base;
	}

	public static function pricing(): string {
		$id = (int) wvm( Settings::class )->get( 'general', 'pricing_page_id', 0 );
		return $id && get_post( $id ) ? (string) get_permalink( $id ) : home_url( '/membership/' );
	}

	public static function enterprise(): string {
		$id = (int) wvm( Settings::class )->get( 'general', 'enterprise_page_id', 0 );
		return $id && get_post( $id ) ? (string) get_permalink( $id ) : home_url( '/enterprise/' );
	}
}
