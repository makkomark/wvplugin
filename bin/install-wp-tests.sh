#!/usr/bin/env bash
# Downloads WordPress + WooCommerce into $WP_CORE_DIR for the PHPUnit integration suite.
# Usage: WP_CORE_DIR=/tmp/wordpress bash bin/install-wp-tests.sh [wp-version] [woo-version]
set -euo pipefail
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
WP_VERSION="${1:-latest}"
WC_VERSION="${2:-latest-stable}"
TMP="$(mktemp -d)"

if [ ! -f "$WP_CORE_DIR/wp-settings.php" ]; then
  mkdir -p "$WP_CORE_DIR"
  if [ "$WP_VERSION" = "latest" ]; then URL="https://wordpress.org/latest.tar.gz"; else URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"; fi
  curl -sSL "$URL" -o "$TMP/wp.tar.gz"
  tar -xzf "$TMP/wp.tar.gz" -C "$TMP"
  cp -R "$TMP/wordpress/." "$WP_CORE_DIR/"
fi

if [ ! -f "$WP_CORE_DIR/wp-content/plugins/woocommerce/woocommerce.php" ]; then
  curl -sSL "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip" -o "$TMP/woo.zip"
  unzip -q "$TMP/woo.zip" -d "$WP_CORE_DIR/wp-content/plugins/"
fi
echo "WordPress + WooCommerce ready in $WP_CORE_DIR"
