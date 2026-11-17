#!/usr/bin/env bash
# Builds an installable plugin zip: production Composer deps (Dompdf) + compiled React apps.
# Usage: bash bin/build-release.sh  → dist/wv-members-<version>.zip
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(grep -m1 " \* Version:" "$ROOT/wv-members.php" | awk '{print $NF}')"
OUT="$ROOT/dist/wv-members"
rm -rf "$ROOT/dist" && mkdir -p "$OUT"

cd "$ROOT"
if [ "${SKIP_JS_BUILD:-0}" != "1" ]; then
  npm ci --no-audit --no-fund && npm run build
fi

rsync -a --exclude-from=- "$ROOT/" "$OUT/" <<'EXCLUDES'
.git
.github
.gitignore
node_modules
vendor
dist
tests
test-results
playwright-report
bin
assets/src
*.log
.phpunit.result.cache
package.json
package-lock.json
webpack.config.js
tsconfig*.json
phpcs.xml
phpstan.neon
phpunit.xml
playwright.config.ts
EXCLUDES

cd "$OUT"
# Production dependencies only. Fall back to git sources where zip downloads are unavailable, then drop VCS metadata.
if ! composer install --no-dev --optimize-autoloader --no-interaction --no-progress 2>/dev/null; then
  composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-install=source
fi
find vendor -type d -name .git -prune -exec rm -rf {} +
rm -f composer.lock

cd "$ROOT/dist" && zip -qr "wv-members-${VERSION}.zip" wv-members
echo "Built dist/wv-members-${VERSION}.zip ($(du -h "wv-members-${VERSION}.zip" | cut -f1))"
