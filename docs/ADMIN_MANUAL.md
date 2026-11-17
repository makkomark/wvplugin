# WitsValley Members — Admin manual

Everything lives under **WitsValley** in wp-admin. What you see depends on your role:

| Role | Can |
| --- | --- |
| Owner (`wvm_owner`, and WordPress administrators) | Everything, including settings, anonymising members and audit |
| Finance | Members (view), payments (approve / reject / record manual / refund), Enterprise, Insights, Audit |
| Support | Members (view + edit: extend, suspend, change plan, notes, impersonate), payments (view), workshops, Enterprise |
| Content | Plans (benefits, level and description only), workshops, content access |

Assign roles in **Users › Edit user › Role**.

## Home
"Needs attention" shows payments waiting for review, members past due, new consultation requests and Enterprise applications,
Kit and email errors. Click a card to jump to the filtered list. The Health panel shows background jobs, payment events, Kit, sandbox mode,
backups and the plugin pages.

## Plans
- **New plan**: slug (cannot change later), name, level (0 Public, 1 Basic, 2 Premium, 3 Enterprise), interval, prices per currency, benefits, visibility.
- **Prices** apply to **new orders only**; existing members keep their price until renewal. After saving a price change you are offered a link to the
  plan's members so you can notify them.
- **Archive** hides a plan from pricing; existing subscriptions continue. **Restore** brings it back. Drag cards to reorder the pricing page.
- Each paid plan has a hidden WooCommerce product (linked as "View Woo product"); never sell it from the shop — the plugin blocks that.

## Members
Filter by plan, status, country, payment method, join date or "renews within N days"; save views; export CSV; bulk-add a Kit tag or send a template.

Member page actions (all ask for a **reason**, which is stored in the audit log):
- **Extend** — adds days to the current period (creates a zero-amount adjustment order).
- **Change plan** — immediately (optionally charging the prorated difference) or at the next renewal.
- **Suspend / Reactivate** — admin overrides. Reactivating a member whose period already ended gives them a fresh grace window to pay.
- **Cancel** — at period end (member keeps access until then) or immediately. **Resume** undoes a scheduled cancellation.
- **Grant plan** — scholarships / comps without payment.
- **Send pay link** — emails a single-use login link to the WooCommerce payment page.
- **Impersonate** — see the dashboard as the member (banner + "Return to admin"); logged.
- **Kit resync** — recalculates and fixes the member's WV-* tags now.
- **Anonymise** (owner) — GDPR: pseudonymises personal data, keeps financial records.

## Payments
- **Needs review**: orders where the amount/currency paid did not match, a payment arrived after cancellation, or activation failed.
  **Approve** activates the membership; **Reject** refunds (if money was taken) or cancels. Both need a reason.
- **Record manual payment** for bank transfers, cash or mobile money received outside a gateway. The membership activates immediately and a receipt is emailed.
- **Refund** full or partial (optionally ending the membership). Gateways that support refunds are refunded automatically; for others, pay the member back yourself.
- **Replay** re-runs activation for an order from the WooCommerce event log (use after fixing an activation error).

## Workshops & consultations
Create a workshop (draft), then **Publish** — eligible members see it in their dashboard and registered members get reminders 24h and 1h before.
The join link appears to eligible members 15 minutes before the start. After the session **Attach recording**. **Notify** emails all eligible members.
Consultations (Premium+) arrive in the inbox: schedule (date + meeting link → the member gets a confirmation), mark done or decline.

## Enterprise
Applications from `/enterprise` arrive in the pipeline (New → In review → Quoted → Accepted/Declined). **Convert** creates an organisation and invites
the contact as its admin. In an organisation: seats, invites (paste emails or CSV), content assignment (only seat holders can read assigned posts),
term dates, suspend/reactivate/renew, and **Invoice order** (creates a payment link for the organisation contact).

## Content access
Set a required level on posts and pages (meta box, quick/bulk edit, or **Content access** screen), restrict to specific plans, or set a default level on a
category. Locked content shows a teaser and a join/upgrade card and is removed from REST, RSS, search and sitemaps. Media files with a level are moved
to a protected folder and served with 15-minute signed links.

## Settings
General (business details, receipts, pages), Payments (sandbox, currencies, gateways per country, transaction-id meta keys), Lifecycle (reminders,
grace, expiry, retries, stale orders, rejoin window; time unit "minute" compresses dunning for staging tests), Emails (sender, provider, templates with
live preview and test send), Email log, Kit (API key, tag map, reconcile), Tools (export/import configuration, health, backup now).
