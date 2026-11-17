# WitsValley Members (`wv-members`)

A WordPress plugin that turns witsvalley.com into a self-running paid membership platform: plans, sign-up and payment,
renewals and dunning, access control for content and media, Kit (ConvertKit) tagging, live workshops and consultations,
Enterprise organisations with seats, a member dashboard and an admin dashboard.

WooCommerce is used **only as payment plumbing** (hidden products, the order-pay page and the gateway plugins).
Everything else — subscriptions, renewals, access, emails — is handled by this plugin and stored in its own `wp_wvm_*` tables.

## Requirements

- PHP 8.1+ (sodium, json, mysqli), WordPress 6.4+, WooCommerce 8+ (the plugin deactivates itself with a notice if Woo is missing)
- Action Scheduler (ships with WooCommerce) for background jobs
- Optional: `WVM_ENCRYPTION_KEY` constant in `wp-config.php` (recommended; otherwise a key is derived from the WP salts)

## Install

1. Build a release zip: `bash bin/build-release.sh` → `dist/wv-members-<version>.zip` (includes Dompdf and the compiled apps), or copy this folder
   to `wp-content/plugins/wv-members` and run `composer install --no-dev` (the plugin also runs without Composer — receipts then fall back to printable HTML).
2. Activate **WooCommerce**, then **WitsValley Members**. Activation creates the tables, roles, the four launch plans, 24 email templates,
   default settings, the Kit tag map and three pages: `/dashboard` (`[wvm_dashboard]`), `/membership` (`[wvm_pricing]`) and `/enterprise` (`[wvm_enterprise_form]`).
3. Open **WitsValley › Settings** to set the business details, currencies, gateways per country, email sender and Kit API key.
   Sandbox mode is ON after install so you can test with the built-in Test gateway.

## What lives where

| | |
| --- | --- |
| Member dashboard | `/dashboard` — React app (`assets/src/member`), hash routes `#/`, `#/workshops`, `#/billing`, `#/profile`, `#/programme`, `#/programme/team` |
| Pricing + sign-up | `/membership` — same bundle, `[wvm_pricing]` (attribute `plan="premium"` preselects a plan) |
| Enterprise form | `/enterprise` — `[wvm_enterprise_form]` |
| Pay links | `/pay/{token}/` → logs the member in once (7-day expiry) → branded WooCommerce order-pay page |
| Enterprise invites | `/enterprise/accept/{token}/` → dashboard invite flow |
| Admin | **WitsValley** menu in wp-admin — React app (`assets/src/admin`) on `@wordpress/components` |
| REST API | `wv-members/v1` — see [docs/API.md](docs/API.md) |

## Development

```bash
composer install
npm install
npm run build          # both apps → build/ (committed so the plugin works from a checkout)
npm run start          # watch mode
npm run typecheck
vendor/bin/phpcs       # WordPress-Extra + WordPress-Docs
WP_CORE_DIR=/path/to/wordpress vendor/bin/phpunit   # needs a WP install with WooCommerce in wp-content/plugins and a throwaway DB (see tests/wp-tests-config.php)
npx playwright test --trace off                      # E2E join flow against WVM_BASE_URL (sandbox mode)
wp eval-file bin/seed-demo.php                       # demo members in every lifecycle state (staging only)
```

Code layout follows the brief: `src/Domain/*` services hold all business rules and write the audit log; `src/Rest/*` controllers are thin;
`src/Woo/*` is the WooCommerce bridge; `src/Jobs/*` are Action Scheduler handlers. Conventions (PSR-4 classes with camelCase methods) are
enforced by `phpcs.xml`. PHPStan level 6 runs in CI.

## Docs

- [Admin manual](docs/ADMIN_MANUAL.md) — day-to-day operations for the WitsValley team
- [Runbook](docs/RUNBOOK.md) — incidents, replays, reconciliation, backups
- [Adding a gateway](docs/ADDING_A_GATEWAY.md) · [Gateway trials](docs/GATEWAYS.md)
- [Adding an email](docs/ADDING_AN_EMAIL.md)
- [Schema notes](docs/SCHEMA.md) · [REST API](docs/API.md)
- [Launch checklist](docs/LAUNCH_CHECKLIST.md) · [Changelog](docs/CHANGELOG.md)
