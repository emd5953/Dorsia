# Product Overview

Dorsia sells certainty: members prepay a dynamically priced minimum spend to lock a hard-to-get table, and the restaurant is paid whether or not they show.

## Core loop

Member picks venue, date and time, then prepays a locked-in minimum spend. Anything above the minimum is settled at the end of the meal with tax and tip; anything below it is kept, not refunded. If no slot fits, a member can submit a custom request with a proposed per-person minimum.

## Membership vs minimum spend

Two separate charges.

| | Membership | Minimum spend |
| --- | --- | --- |
| What | Annual subscription | Per-reservation prepayment |
| Price | $175 to $25K/year by tier | Dynamic per slot, quoted per person (reported $85 to $500 per person in NYC) |
| Applies to food | No (Premium coupons excepted) | Yes, credited to the bill |
| Purpose | Access to the app | Locks a specific table |

## Monetization layers

- **Subscriptions:** tiers built on Stripe Billing, with trials that auto-convert.
- **Closed-loop currency:** Premium is $5,000/year and includes $4,000 in Fun Coupons. Coupons pay table minimums at booking, not the overage, tax or tip.
  - **Premium:** coupons drop every 30 days; each batch expires after 90 days.
  - **Premium Plus:** full balance up front; expires after 12 months.
  - **Both tiers:** all membership coupons expire when the membership ends.
- **Early access by tier:** when a table reopens, members who searched for it are notified first, highest tier first, in random order within a tier; everyone else hears after. A stated tier perk *(proposed in the build plan, not confirmed Dorsia behavior)*.
- **Float and breakage:** cancel 8+ hours out and 100% returns as dining credit, not cash; inside 8 hours, 50% is forfeited. Credits pay future minimums at any venue; they don't expire and can't be cashed out *(assumed)*. Underspend is also breakage: staff post the shortfall as an open "Dorsia Balance" item so the check reaches the minimum, and the member gets nothing back.

## Supply side

Restaurants are paid out via Stripe Connect within 24 hours. They get guaranteed revenue on prime slots and are protected from no-shows financially.

## Demand data (inferred)

Sell-out speed per slot, bids from custom requests, searches for unavailable slots, and spender profiles. This is what lets Dorsia price tables like airline seats.

Sources: [Dorsia FAQ](https://www.dorsia.com/faq) · [Dorsia SevenRooms playbook](https://www.dorsia.com/restaurants/help/playbooks/frictionless-sevenrooms) · [Stripe: Dorsia case study](https://stripe.com/mx/customers/dorsia)

Minimum-spend figures: [Is Dorsia Worth It? (2026)](https://restaurantsforkings.com/blog/dorsia-membership-worth-it-2026) · [Dorsia, WTF? (Eli Feldman)](https://shybird.substack.com/p/dorsia-wtf). Third-party reports; minimums vary by slot.
