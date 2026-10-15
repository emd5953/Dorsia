# Product Overview

Dorsia sells certainty: members prepay a dynamically priced minimum spend to lock a hard-to-get table, and the restaurant is paid whether or not they show.

## Core loop

Member picks venue, date and time, then prepays a locked-in minimum spend. Anything above the minimum is settled at the end of the meal with tax and tip. If no slot fits, a member can submit a custom request with a proposed per-person minimum.

## Membership vs minimum spend

Two separate charges.

| | Membership | Minimum spend |
| --- | --- | --- |
| What | Annual subscription | Per-reservation prepayment |
| Price | $175 to $25K/year by tier | Dynamic, per slot |
| Applies to food | No (Premium coupons excepted) | Yes, credited to the bill |
| Purpose | Access to the app | Locks a specific table |

## Monetization layers

- **Subscriptions:** tiers built on Stripe Billing, with trials that auto-convert.
- **Closed-loop currency:** Premium is $5,000/year and includes $4,000 in Fun Coupons, dropped into the Wallet monthly or quarterly.
- **Float and breakage:** cancel 8+ hours out and 100% returns as dining credit, not cash; inside 8 hours, 50% is forfeited.

## Supply side

Restaurants are paid out via Stripe Connect within 24 hours. They get guaranteed revenue on prime slots and are protected from no-shows financially.

## Demand data (inferred)

Sell-out speed per slot, bids from custom requests, searches for unavailable slots, and spender profiles. This is what lets Dorsia price tables like airline seats.

Sources: [Dorsia FAQ](https://www.dorsia.com/faq) · [Stripe: Dorsia case study](https://stripe.com/mx/customers/dorsia)