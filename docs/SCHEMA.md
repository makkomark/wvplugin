# Schema notes

All tables are `{$wpdb->prefix}wvm_*`, created by `src/Install/Schema.php` with `dbDelta` (additive). Every table has `created_at`
(and `updated_at` where rows change) in UTC. Business records are never hard-deleted from the UI; plans use `archived_at`.

Deviations from the brief, and why:

| Brief | Implemented | Reason |
| --- | --- | --- |
| `plans.interval` | `plans.interval_unit` (API still exposes `interval`) | `INTERVAL` is a reserved word in MySQL |
| `email_templates.key`, `settings.key` | `template_key`, `setting_key` | `KEY` is reserved |
| `audit_log.before/after` | `before_data` / `after_data` (API exposes `before`/`after`) | `BEFORE` is reserved |
| ENUM columns | `VARCHAR` + validation in services (`StateMachine::STATUSES`, `OrderService::STATUSES`, …) | `dbDelta` cannot safely alter ENUMs; adding a status later needs no migration |
| — | `plans.is_quoted`, `plans.highlight` | Enterprise "quoted" plan and the highlighted pricing card |
| — | `subscriptions.pending_plan_id`, `autocharge_attempts` | Downgrade at renewal; auto-charge retry tracking |
| — | `orders.organisation_id`, `description`, `pay_link_used_at`, `receipt_number`, `paid_at` | Enterprise invoices, single-use pay links, receipts |
| — | `members.notification_prefs`, `anonymised_at` | Profile toggles; GDPR pseudonymisation |
| — | `payment_methods.woo_token_id` | Mirror of the WooCommerce payment token |
| — | `organisation_members.subscription_id`, `organisations.programme_title/description` | Seat → subscription link; Programme tab |
| `plan_prices` (no timestamps) | has `created_at/updated_at` | Consistency |

Secrets (`payments.raw_payload`, `payment_methods.token`, Kit/email/S3 keys in `settings`) are encrypted with libsodium `secretbox`
(`src/Support/Encryption.php`); encrypted strings are prefixed `enc:`.

The only meta the plugin writes: post meta `_wvm_level`, `_wvm_plans`, `_wvm_teaser_paragraphs`; term meta `_wvm_level`; Woo product/order meta
`_wvm_plan_id` / `_wvm_order_id`; user meta `wvm_known_devices` (hashed device fingerprints for new-device alerts — no payment data).
