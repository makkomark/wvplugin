# Launch checklist (Gate 4)

- [ ] Final gateway(s) installed in live mode; Settings › Payments › gateways by country updated; transaction meta keys verified with a real payment
- [ ] Sandbox mode **off**; Test gateway no longer offered
- [ ] Real UGX 20,000 Basic payment → member active, receipt received, visible in admin, replay is a no-op (Gate 2)
- [ ] Transactional email provider configured (Postmark/SendGrid/SMTP) with SPF/DKIM on the sending domain; test sends for welcome, receipt, renewal_7d
- [ ] Kit API key set, connection green, tag map reviewed, reconcile run; 20 beta members' tags verified per state
- [ ] `WVM_ENCRYPTION_KEY` defined in wp-config.php
- [ ] Server cron runs Action Scheduler every 5 minutes; no failed `wvm_` actions
- [ ] Lifecycle settings back to **day** units; reminder/grace/expiry values agreed
- [ ] Dashboard, Membership and Enterprise pages published and linked in the main menu; Woo shop/cart/my-account hidden from menus
- [ ] `/my-account` → `/dashboard`, thank-you → dashboard, shop/product → `/membership` verified
- [ ] Protected content check: post with level 2 not visible via REST, RSS, search or direct media URL when logged out (Gate 3)
- [ ] Protected uploads folder denied on the web server (nginx rule in RUNBOOK)
- [ ] Dashboard usable at 360px; full checkout on throttled 3G < 2 min
- [ ] Roles assigned (Owner, Finance, Support, Content) and spot-checked
- [ ] Daily backup produced; S3 upload (if used) verified
- [ ] Analytics events (`wvm_pricing_view`, `wvm_signup_complete`, `wvm_checkout_view`, `wvm_purchase`) seen in the tag manager
- [ ] Admin manual and runbook handed over; credentials (gateways, Kit, email, hosting) owned by WitsValley

Signed off by: ________________________  Date: ____________
