# Adding an email

1. Add the template to `src/Domain/Notification/TemplateDefaults::all()` — key, name, subject, preview and HTML body. Placeholders use `{name}`;
   `[button url="{pay_link}"]Pay now[/button]` renders a branded button. Add any new placeholder names to `TemplateDefaults::PLACEHOLDERS`
   so the editor offers them.
2. If the email is a reminder members can switch off, map it in `TemplateDefaults::preference()` to a notification preference.
3. Send it from a service:
   ```php
   wvm( \WitsValley\Members\Domain\Notification\Notifier::class )->member( $memberId, 'my_key', [ 'plan' => 'Premium' ], 'my_type', 'In-app title', 'In-app body' );
   ```
   or `Mailer::send( 'my_key', 'someone@example.com', [...] )` for non-members. `{first_name}`, `{member_id}`, `{dashboard_url}`, `{business_name}` and
   `{support_email}` are filled automatically; `{pay_link}` is generated when the template uses it.
4. Bump `WVM_DB_VERSION` (the seeder inserts missing templates on upgrade without touching edited ones) and add a line to `docs/CHANGELOG.md`.
5. Staff can then edit the copy in **WitsValley › Settings › Emails**, preview it live and send a test.

Delivery: emails are queued in `wp_wvm_notifications` and sent by Action Scheduler (3 attempts). Drivers: `wp_mail` (use an SMTP plugin),
Postmark or SendGrid (API key in Settings › Emails; stored encrypted).
