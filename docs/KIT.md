# How WitsValley Members works with Kit (ConvertKit)

**Division of labour:** the plugin decides *who has access to what*; Kit *delivers the content*.
The plugin never sends lessons. It keeps a small set of **tags** on each member's Kit subscriber up to date,
and your Kit **automations** (tag added → start sequence, etc.) do the rest.

## Setup (once)
1. Kit › Settings › Developer › API Keys: create a **v4 API key**.
2. WitsValley › Settings › Kit: paste the key (stored encrypted), press **Test connection**.
3. Same screen, **Unsubscribe webhooks** › **Connect Kit webhooks** (needs the live site on public HTTPS).
4. Press **Reconcile now** once so existing members get their tags.
5. In Kit, build automations on the tags below, e.g. *"Tag WV-BASIC added → subscribe to Basic sequence"*,
   *"Tag WV-EXPIRED added → remove from Basic/Premium sequences, start win-back sequence"*.
   Missing tags are created in Kit automatically the first time they are used.

## The tags
Only tags starting with `WV-` are ever added or removed. Any other tags you use in Kit are never touched.

| Member state | Tags added | Tags removed |
| --- | --- | --- |
| Free | `WV-FREE` | — |
| Active paid (plan) | the plan's tag (`WV-BASIC`, `WV-PREMIUM`) | `WV-FREE`, `WV-EXPIRED`, `WV-CANCELLED`, `WV-PAST-DUE`, other plan tags |
| Past due (grace period, still has access) | plan tag, `WV-PAST-DUE` | — |
| Suspended / expired | `WV-EXPIRED` | plan tags, `WV-PAST-DUE` |
| Cancelled (membership ended) | `WV-CANCELLED` | plan tags, `WV-PAST-DUE` |
| Enterprise seat holder | `WV-ENTERPRISE` (in addition to any personal plan) | — |
| Opted in to offers and news (any state) | `WV-MARKETING` | — (removed when they opt out) |

Each plan's tag is set on the plan (WitsValley › Plans › General › Kit tag). The whole table is editable under
Settings › Kit › Tag map (tokens: `{plan_tag}`, `{plan_tags}`, `{other_plan_tags}`). The marketing tag's name is set
under Settings › Kit › Connection › Marketing tag (must start with `WV-`; empty turns it off).

**Two kinds of email.** Membership emails (the Free newsletter, lessons, programme content) go to everyone with the
right membership tag. Promotional emails (offers, launches, news) should go **only** to `WV-MARKETING`.

Examples: Free → Basic gives `WV-BASIC` and removes `WV-FREE`. Basic → Premium swaps `WV-BASIC` for `WV-PREMIUM`.
A renewal that isn't paid adds `WV-PAST-DUE`; paying removes it; never paying ends with `WV-EXPIRED`.

## When a sync happens
A background job (Action Scheduler, ~5 seconds later) re-syncs a member after:
- sign-up, any subscription change (join, payment, renewal, upgrade/downgrade, past due, suspended, expired, cancelled, restored),
- a marketing-consent change, an email-address change (the Kit subscriber's email is updated too),
- an admin **Kit resync** on the member page, or a bulk "add Kit tag".

Every night a **reconcile** re-checks every member and fixes drift (e.g. a tag removed by hand in Kit).
It runs at 25 members per minute to stay under Kit's ~120 requests/minute limit; if Kit still says "slow down"
(HTTP 429) the job retries a minute later instead of failing.

## Who becomes a Kit subscriber
- **Free members**: yes, the Free plan includes the weekly newsletter (Settings › Kit › "Add free members to Kit", on by default).
- **Paying members** (and Enterprise seats): yes, so they receive the content they paid for ("Add paying members to Kit", on by default).
- **Members who ticked "Also send me WitsValley offers and news"**: yes, and they also get `WV-MARKETING`. The switch starts
  off on the sign-up and invite forms; members can change it any time in Profile › Notifications › "Offers and news".
- **Never** someone Kit has reported as unsubscribed, bounced or complained (see below), so the plugin can't re-subscribe them.
- An existing Kit subscriber with the same email is reused (matched case-insensitively), so there is exactly one subscriber per person.
- New subscribers are created as *active*.

## Unsubscribes (Kit → plugin)
With the webhooks connected, Kit tells the plugin when someone:
- **unsubscribes**, **bounces** or **marks an email as spam**: their marketing consent is withdrawn, the member page shows
  a warning, and the plugin will not create or re-subscribe them. Their `WV-*` tags still follow their membership.
- **subscribes again** (for example through a Kit form): the block is lifted.

The webhook address contains a random secret; **Reconnect** replaces it, **Disconnect** removes the webhooks from Kit.
Without the webhooks, an unsubscribe in Kit still stops Kit emailing them, but the plugin doesn't know about it.

Opting out of "offers and news" in the plugin only removes `WV-MARKETING`. It does **not** unsubscribe the person
from Kit, because their membership emails still need to reach them. To stop everything, they use the unsubscribe link in any Kit email.

## Where to look when something is off
- Settings › Kit: connection, last reconcile, errors in the last 24h, full sync log (every API call).
- Member page › Access tab: the member's expected state/tags, Kit subscriber ID, last sync and last error; **Kit resync** button.
- Home › Needs attention shows Kit errors; a daily health email fires if there are more than 5 Kit errors in 24h.

## Not done automatically (by design, today)
- Tag changes made by hand in Kit are not read back; the nightly reconcile re-applies the `WV-*` tags.
- Renaming the marketing tag leaves the old tag on subscribers; delete it in Kit if you no longer need it.
