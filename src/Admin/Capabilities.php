<?php
declare( strict_types=1 );

namespace WitsValley\Members\Admin;

/**
 * Roles and capabilities (§3 of the brief).
 */
final class Capabilities {

	/** @var array<string,list<string>> capability => roles */
	public const MATRIX = array(
		'wvm_manage_settings'       => array( 'wvm_owner' ),
		'wvm_manage_plans'          => array( 'wvm_owner', 'wvm_content' ),
		'wvm_view_members'          => array( 'wvm_owner', 'wvm_finance', 'wvm_support' ),
		'wvm_edit_members'          => array( 'wvm_owner', 'wvm_support' ),
		'wvm_view_payments'         => array( 'wvm_owner', 'wvm_finance', 'wvm_support' ),
		'wvm_manage_payments'       => array( 'wvm_owner', 'wvm_finance' ),
		'wvm_manage_workshops'      => array( 'wvm_owner', 'wvm_support', 'wvm_content' ),
		'wvm_manage_enterprise'     => array( 'wvm_owner', 'wvm_finance', 'wvm_support' ),
		'wvm_view_insights'         => array( 'wvm_owner', 'wvm_finance' ),
		'wvm_manage_content_access' => array( 'wvm_owner', 'wvm_content' ),
		'wvm_impersonate'           => array( 'wvm_owner', 'wvm_support' ),
		'wvm_view_audit'            => array( 'wvm_owner', 'wvm_finance' ),
	);

	/**
	 * Capability granted to the Content role for plans: benefits/levels only.
	 * Full plan edits (prices, slug, visibility, archive) need wvm_manage_plans_full.
	 */
	public const PLANS_FULL = 'wvm_manage_plans_full';

	/** @var array<string,string> */
	public const ROLES = array(
		'wvm_owner'   => 'WitsValley Owner',
		'wvm_finance' => 'WitsValley Finance',
		'wvm_support' => 'WitsValley Support',
		'wvm_content' => 'WitsValley Content',
	);

	/** @return list<string> */
	public static function all(): array {
		return array_merge( array_keys( self::MATRIX ), array( self::PLANS_FULL ) );
	}

	public static function install(): void {
		foreach ( self::ROLES as $role => $label ) {
			$caps = array(
				'read'         => true,
				'wvm_access'   => true,
				'upload_files' => 'wvm_content' === $role,
			);
			foreach ( self::MATRIX as $cap => $roles ) {
				if ( in_array( $role, $roles, true ) ) {
					$caps[ $cap ] = true;
				}
			}
			if ( 'wvm_owner' === $role ) {
				$caps[ self::PLANS_FULL ] = true;
			}
			if ( 'wvm_content' === $role ) {
				$caps['edit_posts']           = true;
				$caps['edit_others_posts']    = true;
				$caps['edit_published_posts'] = true;
				$caps['edit_pages']           = true;
				$caps['edit_others_pages']    = true;
				$caps['edit_published_pages'] = true;
				$caps['manage_categories']    = true;
			}
			remove_role( $role );
			add_role( $role, $label, $caps );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'wvm_access' );
			foreach ( self::all() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	public static function uninstall(): void {
		foreach ( array_keys( self::ROLES ) as $role ) {
			remove_role( $role );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( 'wvm_access' );
			foreach ( self::all() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	/** Capabilities of the current user (for the admin app). @return array<string,bool> */
	public static function forCurrentUser(): array {
		$out = array();
		foreach ( array_merge( self::all(), array( 'wvm_access' ) ) as $cap ) {
			$out[ $cap ] = current_user_can( $cap );
		}
		return $out;
	}

	public static function canAccessAdmin(): bool {
		return current_user_can( 'wvm_access' ) || current_user_can( 'manage_options' );
	}
}
