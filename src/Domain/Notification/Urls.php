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
		if ( ! $route ) {
			return $base;
		}
		return ( str_contains( $base, '?' ) ? $base : trailingslashit( $base ) ) . '#/' . ltrim( $route, '/' );
	}

	/** Single-use pay link. Pretty permalinks: /pay/{token}/; plain permalinks: ?wvm_pay={token}. */
	public static function payLink( string $token ): string {
		return get_option( 'permalink_structure' ) ? home_url( '/pay/' . $token . '/' ) : add_query_arg( 'wvm_pay', $token, home_url( '/' ) );
	}

	/** Enterprise invite link (works with any permalink setting). */
	public static function inviteLink( string $token ): string {
		return get_option( 'permalink_structure' ) ? home_url( '/enterprise/accept/' . $token . '/' ) : add_query_arg( 'wvm_accept', $token, home_url( '/' ) );
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
