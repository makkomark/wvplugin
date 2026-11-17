<?php
/**
 * Uninstall: data is kept unless WVM_REMOVE_DATA_ON_UNINSTALL is defined true in wp-config.php.
 *
 * @package WitsValley\Members
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/wv-members.php';

\WitsValley\Members\Install\Installer::uninstall();
