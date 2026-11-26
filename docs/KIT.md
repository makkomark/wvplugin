# How WitsValley Members works with Kit (ConvertKit)

**Division of labour:** the plugin decides *who has access to what*; Kit *delivers the content*.
The plugin never sends lessons. It keeps a small set of **tags** on each member's Kit subscriber up to date,
and your Kit **automations** (tag added → start sequence, etc.) do the rest.

## Setup (once)
1. Kit › Settings › Developer › API Keys: create a **v4 API key**.
2. WitsValley › Settings › Kit: paste the key (stored encrypted), press **Test connection**.
3. In Kit, build automations on the tags below, e.g. *"Tag WV-BASIC added → subscribe to Basic sequence"*,
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

Each plan's tag is set on the plan (WitsValley › Plans › General › Kit tag). The whole table is editable under
Settings › Kit › Tag map (tokens: `{plan_tag}`, `{plan_tags}`, `{other_plan_tags}`).

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
- **Paying members** (and Enterprise seats): always, so they receive the content they paid for
  (Settings › Kit › "Create subscribers on paid", on by default).
- **Free members**: only if they ticked *"Send me WitsValley insights and news"* at sign-up (or later in their profile).
- An existing Kit subscriber with the same email is reused (matched case-insensitively), so there is exactly one subscriber per person.
- New subscribers are created as *active*.

## Where to look when something is off
- Settings › Kit: connection, last reconcile, errors in the last 24h, full sync log (every API call).
- Member page › Access tab: the member's expected state/tags, Kit subscriber ID, last sync and last error; **Kit resync** button.
- Home › Needs attention shows Kit errors; a daily health email fires if there are more than 5 Kit errors in 24h.

## Not done automatically (by design, today)
- Kit → plugin: unsubscribes or tag changes made in Kit are not read back (the nightly reconcile re-applies the `WV-*` tags).
- Withdrawing marketing consent does **not** unsubscribe the person in Kit; see the open decisions in the changelog/handover.
