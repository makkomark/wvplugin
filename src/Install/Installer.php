<?php
declare( strict_types=1 );

namespace WitsValley\Members\Install;

use WitsValley\Members\Admin\Capabilities;
use WitsValley\Members\Jobs\Scheduler;

/**
 * Activation / deactivation / upgrades. Deactivation never deletes data.
 */
final class Installer {

	public static function activate(): void {
		if ( ! class_exists( 'WooCommerce' ) && ! defined( 'WVM_TESTING' ) ) {
			deactivate_plugins( plugin_basename( WVM_FILE ) );
			wp_die(
				esc_html__( 'WitsValley Members needs WooCommerce 8 or newer. Install and activate WooCommerce, then activate WitsValley Members again.', 'wv-members' ),
				esc_html__( 'WooCommerce required', 'wv-members' ),
				array( 'back_link' => true )
			);
		}
		self::install();
		( new \WitsValley\Members\Frontend\Frontend( wvm( \WitsValley\Members\Support\Settings::class ), wvm( \WitsValley\Members\Domain\Order\OrderRepository::class ) ) )->rewrites();
		flush_rewrite_rules( false );
		set_transient( 'wvm_activated', 1, 60 );
	}

	public static function install(): void {
		Schema::install();
		Migrations::run( (string) get_option( 'wvm_db_version', '0' ) );
		Capabilities::install();
		Seeder::run();
		update_option( 'wvm_db_version', WVM_DB_VERSION, false );
		self::ensureRecurringJobs();
	}

	public static function maybeUpgrade(): void {
		if ( version_compare( (string) get_option( 'wvm_db_version', '0' ), WVM_DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	public static function ensureRecurringJobs(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && did_action( 'action_scheduler_init' ) ) {
			wvm( Scheduler::class )->ensureRecurring();
		} elseif ( function_exists( 'as_has_scheduled_action' ) ) {
			add_action( 'action_scheduler_init', static fn() => wvm( Scheduler::class )->ensureRecurring() );
		}
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array_keys( Scheduler::DAILY ) as $hook ) {
				as_unschedule_all_actions( $hook, array(), Scheduler::GROUP );
			}
		}
		flush_rewrite_rules( false );
	}

	/** Called from uninstall.php. Keeps data unless WVM_REMOVE_DATA_ON_UNINSTALL is true. */
	public static function uninstall(): void {
		if ( ! defined( 'WVM_REMOVE_DATA_ON_UNINSTALL' ) || ! WVM_REMOVE_DATA_ON_UNINSTALL ) {
			return;
		}
		Schema::drop();
		Capabilities::uninstall();
		delete_option( 'wvm_db_version' );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wvm_level','_wvm_plans','_wvm_teaser_paragraphs')" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_wvm_level'" ); // phpcs:ignore WordPress.DB
	}
}
