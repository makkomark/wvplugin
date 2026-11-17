# Changelog

## 1.0.0 — 2026-11-17
Initial build of all phases in the brief:
- **Phase 0** Foundations: plugin bootstrap, PSR-4, 23 custom tables, roles & capabilities, encrypted settings, Money/Clock/Validation/Pagination helpers,
  plans CRUD with prices/reorder/archive and Woo product sync, members, audit log, Test gateway + sandbox mode, seed data, CI.
- **Phase 1** Payments & join flow: orders bridged to WooCommerce, idempotent order listener with amount/currency verification and review queue,
  hook inbox + replay, payment records with per-gateway transaction-id keys, branded order-pay page, gateway filter by country, redirects,
  Woo email suppression, public sign-up, pay links, pending-status polling, stale-order job, transactional mailer + queue + PDF receipts.
- **Phase 2** Subscriptions & access: state machine, renewals anchored to period end, reminders, grace, suspension, expiry, restore, auto-charge adapters
  with retries, prorated upgrades, downgrades at renewal, cancel/resume, admin extend/suspend/reactivate/grant; level resolver, content guard
  (content, excerpt, REST, RSS, search, sitemap, comments, cache headers), protected media with signed URLs, meta box/term defaults/bulk edit.
- **Phase 3** Member dashboard, admin dashboard, Kit sync + reconciler, workshops & consultations (ICS, join gating, reminders), Enterprise
  (applications, organisations, seats, invites, content assignment, invoice orders), insights, audit viewer, settings, tools.
- **Phase 4** Health checks + alert emails, daily backups (local + S3-compatible), docs, release script.
