<?php
/**
 * WordPress test-suite config. All values come from env vars so CI and local runs share it.
 *   WP_CORE_DIR   path to a WordPress install (ABSPATH)
 *   WP_TESTS_DB_* database for tests (it is wiped!)
 */
define( 'ABSPATH', rtrim( getenv( 'WP_CORE_DIR' ) ?: dirname( __DIR__ ) . '/tests/wordpress', '/' ) . '/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'wptest' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'wp' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASS' ) ?: 'wp' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'WitsValley Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WVM_ENCRYPTION_KEY', 'test-encryption-key' );
if ( ! defined( 'WVM_TESTING' ) ) {
	define( 'WVM_TESTING', true );
}
