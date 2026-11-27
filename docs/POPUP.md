# Membership popup

WitsValley › Settings › Popup. Off until you switch it on.

## Set it up
1. Upload the free book (PDF) and its cover to the Media Library, and pick them under **The free book**.
2. Check the words and the live preview (Visitors / Free members).
3. In Kit, create an automation: **tag `WV-FREE-BOOK` added** → send the book email, then your guide sequence.
4. Press **Preview on site** to try the saved version (only you see it), then switch **Show the popup** on and save.

## Who sees what
| Person | Sees |
| --- | --- |
| Visitor (not logged in) | The free-book offer: *Join free* (name, email, password) or, in *Email only* mode, name and email |
| Logged-in free member | The upgrade popup (opens the pricing page on the chosen plan) |
| Paying member, staff without a membership | Nothing |

Never shown on the membership, dashboard, enterprise, pay-link, cart, checkout or account pages, or on paths you exclude.

## When it appears
Whichever comes first: seconds on the page, scroll depth, the mouse leaving the window (desktop only), or reaching a
members-only paywall (this one ignores the page-view minimum). At most once per visit; after someone closes it, it stays
hidden for the number of days you set. On phones it's a bottom sheet and the keyboard doesn't open by itself.

## What happens on sign-up
- **Join free:** a free membership is created and they're logged in. The popup shows a *Download your free book* button
  (when a book file is set) and *Go to my dashboard*. In Kit they get `WV-FREE` and `WV-FREE-BOOK`, even if
  "Add free members to Kit" is off, because they asked for the book.
- **Email only:** a Kit subscriber is created with `WV-LEAD` and `WV-FREE-BOOK`. No membership, so use `WV-LEAD` for a
  "become a member" follow-up. Needs Kit connected.

## Results
Settings › Popup › Results: views, closes, sign-ups and conversion (sign-ups ÷ views) per version, plus **Later paid**:
popup sign-ups who have since bought a paid plan. With a second headline set, half of visitors see each; keep the winner
after a few hundred views each.
