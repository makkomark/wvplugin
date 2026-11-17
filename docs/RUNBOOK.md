# Runbook

## A member paid but is not active
1. **WitsValley › Payments**, search their email. Open the order.
2. Status `needs_review` → read the reason (amount/currency mismatch, paid after cancel, activation error). Approve or reject with a reason.
3. Status `pending` but the gateway shows paid → the gateway did not tell WooCommerce. Check the Woo order notes / gateway dashboard; once the Woo
   order is Processing/Completed the plugin activates automatically. If needed, mark the Woo order *Processing* manually, or record a manual payment.
4. After fixing code or config that caused an `activation_error`, press **Replay** on the order.

Every WooCommerce status event is stored in `wp_wvm_hook_inbox` (visible as "Hook log" in the order drawer), so nothing is lost.

## Renewals / dunning did not run
- Jobs run through Action Scheduler (group `wv-members`): **WooCommerce › Status › Scheduled Actions**, search `wvm_`. Failed actions show the error.
- Make sure WP-Cron (or a real cron calling `wp action-scheduler run`) is running. Recommended server cron: `*/5 * * * * wp action-scheduler run --group=wv-members`.
- The daily `wvm_job_daily_reconcile` catches any subscription whose deadline passed without its job running.

## Kit tags look wrong
**WitsValley › Settings › Kit**: check connection and the sync log. Member page → **Kit resync** fixes one member; **Reconcile now** queues everyone.
Only `WV-*` tags are ever changed.

## Emails not arriving
**Settings › Email log**: failed rows show the provider error; **Retry**. Each email retries 3× (1, 5, 25 minutes). With `wp_mail`, an SMTP plugin is
required on production.

## Health alerts
The daily health job emails the alert address when: no payment events for 48h while orders are pending, more than 10 failed jobs in 24h, or more than
5 Kit errors in 24h.

## Backups
Daily `wvm_job_backup` writes `uploads/wvm-protected/backups/wvm-backup-YYYYMMDD-HHMMSS.json.gz` (all plugin tables, kept 14 days) and uploads to the
S3-compatible bucket if configured (Settings › Tools). Restore by importing the JSON rows into the matching `wp_wvm_*` tables (staging first).

## Protected folders on nginx
Apache is protected by `.htaccess`. On nginx add:
```
location ^~ /wp-content/uploads/wvm-protected/ { deny all; return 403; }
```

## Staging dunning test (Gate 3)
Settings › Lifecycle: time unit **minute**, grace 3, suspend→expire 3, reminders [2,1]; create a plan with interval *custom* and interval count 5 (5 minutes).
Join with the Test gateway and watch active → past_due → suspended → expired over ~15 minutes. Set the unit back to **day** afterwards.

## Rotating the encryption key
Secrets are encrypted with `WVM_ENCRYPTION_KEY`. Before changing it, note the Kit/email/S3 keys; after changing it, re-enter them in Settings.
Stored payment tokens will need members to re-save their card.
