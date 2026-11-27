<?php
/**
 * Plugin Name:       WitsValley Members
 * Plugin URI:        https://witsvalley.com
 * Description:       Self-running paid membership platform: plans, subscriptions, renewals, access control, Kit (ConvertKit) sync, workshops and enterprise programmes. WooCommerce is used for payment plumbing only.
 * Version:           1.0.4
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            WitsValley
 * Text Domain:       wv-members
 * Domain Path:       /languages
 * WC requires at least: 8.0
 *
 * @package WitsValley\Members
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'WVM_VERSION', '1.0.4' );
define( 'WVM_DB_VERSION', '1.0.4' );
define( 'WVM_FILE', __FILE__ );
define( 'WVM_DIR', plugin_dir_path( __FILE__ ) );
define( 'WVM_URL', plugin_dir_url( __FILE__ ) );
define( 'WVM_REST_NS', 'wv-members/v1' );

if ( file_exists( WVM_DIR . 'vendor/autoload.php' ) ) {
	require_once WVM_DIR . 'vendor/autoload.php';
}

// Fallback PSR-4 autoloader so the plugin runs from a plain checkout without `composer install`.
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'WitsValley\\Members\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = WVM_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once WVM_DIR . 'src/functions.php';

register_activation_hook( __FILE__, array( \WitsValley\Members\Install\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WitsValley\Members\Install\Installer::class, 'deactivate' ) );

// Declare WooCommerce HPOS + cart/checkout blocks compatibility.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p><strong>WitsValley Members</strong> requires WooCommerce 8+ to be installed and active. The plugin has been deactivated.</p></div>';
				}
			);
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			deactivate_plugins( plugin_basename( WVM_FILE ) );
			return;
		}
		\WitsValley\Members\Plugin::instance()->boot();
	},
	20
);
