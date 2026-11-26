# Changelog

## 1.0.3 — 2026-11-26
- **Kit: free members get the newsletter.** Free members are added to Kit (tag `WV-FREE`) without a separate tick-box, because the
  Free plan includes the weekly newsletter (Settings › Kit › "Add free members to Kit", on by default).
- **Kit: separate marketing consent.** New `WV-MARKETING` tag (Settings › Kit › Marketing tag) is added while a member has opted in
  to offers and news and removed when they opt out. Send promotional broadcasts to this tag only.
- Sign-up and invite forms: the consent switch now reads "Also send me WitsValley offers and news (optional)" and starts **off**.
  The profile toggle is now "Offers and news" and explains that membership emails are separate.
- **Kit → plugin webhooks.** Settings › Kit › "Connect Kit webhooks" registers unsubscribe, bounce, complaint and re-subscribe
  webhooks. An unsubscribe withdraws marketing consent and stops the plugin from re-creating or re-subscribing that person;
  subscribing again through a Kit form lifts the block. The member page and Kit status show unsubscribed members.
- Kit status layout: connection and action on top, the four counters in one row.

- New pricing page header ("WitsValley Membership — Reprogram Your Subconscious Mind. Transform Your Life." + subheadline, introduction
  and closing line), editable in Settings › General › Pricing page header.
- **Fix: unreadable pricing section on devices in dark mode.** Pricing and enterprise sections always follow the (light) theme; the
  dashboard's dark mode is now opt-in (Settings › Branding), and existing installs are switched to "always light".
- **Fix: Kit rate limits.** The nightly reconcile and bulk tagging are paced (25 members/minute) and an HTTP 429 from Kit
  schedules a retry instead of recording a sync error. New docs/KIT.md explains the integration.
- **Fix: gateways charging the wrong currency.** Some gateway plugins (e.g. JPesa) send the store currency (often USD) with the
  order total, so a UGX 20,000 membership was charged as USD 20,000. While a WitsValley order is being paid, WooCommerce now
  reports the order's own currency and decimals to every gateway (pay page, pay form, thank-you page, gateway callbacks, auto-charges, manual payments).
- New **Settings › Payments › Gateway currencies**: gateways are only offered for currencies they can charge
  (PayPal is hidden on UGX orders by default).
- Health check warns when the WooCommerce store currency differs from the main membership currency.
- Payment page: gateway rows (radio, name, logo) stay on one line in any theme, price line no longer breaks, brand name uses the primary colour.
- New plan copy for Free, Basic and Premium (tagline, description, "What's included" list). Existing installs are updated automatically
  unless an admin already edited the plan wording.
- Plans have a **tagline** (bold one-line promise on the pricing card), editable in WitsValley › Plans (also by the Content role).
- Pricing cards show "Free/Basic/Premium Membership", a "What's included:" label, the "Most popular" badge on the card edge, and stay aligned.
- Pricing, Enterprise, consultation and consent wording rewritten for WitsValley's personal-transformation focus (replacing generic finance placeholder text).
- Remaining lime-green glows replaced with the amber accent.

## 1.0.1 — 2026-11-17
- WitsValley brand colours everywhere: primary green `#0B4C25` + amber accent `#FFAC04` (member app, pricing, checkout, paywall, admin, emails, receipts, charts).
- New **Settings › Branding**: brand colours with live preview (all shades, gradients and dark-mode variants derived automatically), font source
  (theme fonts read from the active block theme, system, Google Fonts, Adobe Fonts kit, custom stylesheet), separate heading font, wp-admin font choice, dark-mode switch.
- Neutral greys re-tinted to a sage palette that suits the green.
- WooCommerce cart icons hidden in theme headers (the storefront is hidden).

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
