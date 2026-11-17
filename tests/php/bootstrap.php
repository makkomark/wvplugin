<?php
/**
 * PHPUnit bootstrap: WordPress test suite + WooCommerce + WitsValley Members.
 *
 * @package WitsValley\Members\Tests
 */

$root = dirname( __DIR__, 2 );
require_once $root . '/vendor/autoload.php';
require_once $root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $root . '/tests/wp-tests-config.php' );
$tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: $root . '/vendor/wp-phpunit/wp-phpunit';
$core      = rtrim( getenv( 'WP_CORE_DIR' ) ?: $root . '/tests/wordpress', '/' );
$woo       = getenv( 'WC_DIR' ) ?: $core . '/wp-content/plugins/woocommerce';

require_once $tests_dir . '/includes/functions.php';

// Start every run from an empty test database (plugin + WooCommerce tables are not dropped by the WP installer).
( static function (): void {
	$db = new mysqli( getenv( 'WP_TESTS_DB_HOST' ) ?: '127.0.0.1', getenv( 'WP_TESTS_DB_USER' ) ?: 'wp', getenv( 'WP_TESTS_DB_PASS' ) ?: 'wp', getenv( 'WP_TESTS_DB_NAME' ) ?: 'wptest' );
	$res = $db->query( "SHOW TABLES LIKE 'wptests\\_%'" );
	$db->query( 'SET FOREIGN_KEY_CHECKS = 0' );
	while ( $res && ( $row = $res->fetch_row() ) ) {
		$db->query( 'DROP TABLE IF EXISTS `' . $row[0] . '`' );
	}
	$db->close();
} )();

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $root, $woo ): void {
		require_once $woo . '/woocommerce.php';
		require_once $root . '/wv-members.php';
	}
);

tests_add_filter(
	'init',
	static function (): void {
		if ( class_exists( 'WC_Install' ) ) {
			\WC_Install::install();
			update_option( 'woocommerce_currency', 'UGX' );
			if ( function_exists( 'WC' ) ) {
				WC()->init();
			}
		}
		\WitsValley\Members\Install\Installer::install();
	},
	30
);

require $tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/TestCase.php';
