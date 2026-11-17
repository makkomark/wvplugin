#!/usr/bin/env bash
# Builds an installable plugin zip: production Composer deps (Dompdf) + compiled React apps.
# Usage: bash bin/build-release.sh  → dist/wv-members-<version>.zip
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(grep -m1 "Version:" "$ROOT/wv-members.php" | awk '{print $NF}')"
OUT="$ROOT/dist/wv-members"
rm -rf "$ROOT/dist" && mkdir -p "$OUT"

cd "$ROOT"
npm ci && npm run build
rsync -a --exclude-from=- "$ROOT/" "$OUT/" <<'EXCLUDES'
.git
.github
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
package-lock.json
EXCLUDES
cd "$OUT" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress
cd "$ROOT/dist" && zip -qr "wv-members-${VERSION}.zip" wv-members
echo "Built dist/wv-members-${VERSION}.zip"
