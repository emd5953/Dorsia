# Service Map (inferred)

The Laravel API likely holds six domains in one codebase; the wallet ledger carries the most risk because every forfeit, credit and coupon must reconcile.

```mermaid
flowchart TB
    App["Member app<br/>React Native + Expo"]
    Admin["Admin tool<br/>Staff actions, writes via API"]
    BI["BI tool<br/>Dashboards, read-only"]

    subgraph API["Laravel API on AWS Lambda (Vapor), REST + GraphQL"]
        Inv["Inventory<br/>Slots, drops, holds"]
        Price["Pricing<br/>Dynamic minimum per slot"]
        Res["Reservations<br/>Book, modify, cancel"]
        Wallet["Wallet ledger<br/>Cash, credits, Fun Coupons"]
        Mem["Memberships<br/>Tiers, trials, renewals"]
        Int["Restaurant integrations<br/>Sync + Stripe Connect payouts"]
    end

    DB["Postgres + Redis"]
    Pusher["Pusher<br/>Live slot updates"]
    Stripe["Stripe<br/>Billing + payouts"]
    Rest["Restaurant systems<br/>Table and POS data"]

    App --> API
    Admin --> API
    API --> DB
    BI -. "read-only" .-> DB
    API --> Pusher
    API --> Stripe
    API --> Rest
```

| Domain | Owns |
| --- | --- |
| Inventory | Slots, drops, holds; releases tables on schedule |
| Pricing | Dynamic minimum per slot from urgency, timing, demand |
| Reservations | Book, modify, cancel; the 8-hour cancellation rule |
| Wallet ledger | Cash, credits, Fun Coupons; forfeits and coupon expiry; overage settled at end of meal; staff compensation charged to Dorsia or the venue |
| Memberships | Tiers, trials, renewals via Stripe Billing |
| Restaurant integrations | Sync with restaurant systems; payouts via Stripe Connect |

## Booking flow

1. App asks Inventory for a slot.
2. Pricing sets the minimum.
3. Reservations charges it through the Wallet and Stripe.
4. Pusher broadcasts the slot as taken.