# Dorsia-Scale Build Plan

*October 15, 2026*

A modular Laravel monolith on Postgres and Redis handles Dorsia's likely scale; the hard part is correctness during reservation drops, not raw traffic. A drop is a scheduled release of a venue's tables, which members race to book.

---

## 1. Scale targets

We design for 10x Dorsia's estimated load, so the plan holds through their next growth phase. Only venue and city counts are published; the rest are estimates.

| Metric | Dorsia today (est.) | Design target |
| --- | --- | --- |
| Venues | 400+ (published) | 5,000 |
| Cities | 25 (published) | 100 |
| Bookable slots per day | ~8,000 | 100,000 |
| Members | Tens of thousands | 500,000 |
| Bookings per day | Low thousands | 25,000 |
| Drop burst | Thousands of users on one venue in seconds | 5,000 concurrent users on 50 tables in 10 seconds |
| Booking API p95 latency | Unknown | Under 300 ms during a drop |
| Double bookings | Zero | Zero, proven by load test |
| Ledger drift | Zero | Zero, checked nightly |

The last three rows are the real requirements. Steady traffic at this size fits on one Postgres primary.

---

## 2. Architecture

A single Laravel app with six internal modules mirrors Dorsia's setup and is the simplest thing that survives a drop.

```mermaid
flowchart TB
    App["Member app<br/>Expo, iOS and Android"]
    Admin["Admin tool<br/>Staff actions, writes via API"]
    BI["BI tool<br/>Dashboards, read-only"]
    K6["k6 drop test<br/>5,000 virtual members"]
    Edge["Cloudflare: WAF, rate limits, bot filtering, waiting room"]

    subgraph API["Laravel modular monolith (Octane locally, Vapor in production)"]
        Inv["Inventory<br/>Slots and holds"]
        Price["Pricing<br/>One quote per hold"]
        Res["Reservations<br/>State machine"]
        Wallet["Wallet<br/>Double-entry ledger"]
        Mem["Memberships<br/>Tiers and coupon grants"]
        Int["Integrations<br/>Venue sync"]
    end

    PG["Postgres<br/>Truth and constraints"]
    Redis["Redis<br/>Gate, cache, queues"]
    RT["Reverb / Pusher<br/>Pushes via outbox"]
    GW["Payment gateway<br/>Fake now, Stripe later"]

    App --> Edge
    Admin --> Edge
    K6 --> Edge
    Edge --> API
    API --> PG
    API --> Redis
    API --> RT
    API --> GW
    BI -. "read-only role" .-> PG
```

Requests pass Cloudflare first; inside, only a Postgres write can confirm a booking, while Redis and Reverb make it fast and live. The admin tool writes only through the API, so staff credits and refunds hit the same ledger rules as member actions; the BI tool only reads, through a read-only Postgres role.

**Admin API.** Staff actions live in the same Laravel app under `/admin/*`, as thin controllers over the existing module actions. The admin tool is just a client; any front end works.

| Endpoint | Action called | Permission |
| --- | --- | --- |
| `POST /admin/members/{id}/credits` | `Wallet\IssueGoodwillCredit` | support |
| `POST /admin/reservations/{id}/compensate` | `Wallet\CompensateMember` (`amount`, `charge_to`, `reason_code`) | support; `charge_to=venue` needs support_lead |
| `POST /admin/reservations/{id}/cancel` | `Reservations\CancelReservation` (staff override of the 8-hour rule) | support |
| `POST /admin/payments/{id}/refund` | `Wallet\RefundPayment` | finance |
| `POST /admin/slots/{id}/release` | `Inventory\ReleaseSlot` | venue_ops |
| `PATCH /admin/venues/{id}` | `Integrations\UpdateVenue` | venue_ops |

- Separate staff auth guard (SSO), so a member token can never reach `/admin`.
- Every admin write requires a `reason` and an idempotency key, and records the staff user in an `admin_audit_log` row in the same transaction.
- Money actions above a threshold need a second approver; the threshold is a config value, not code.
- The database role used by the API is the only one with write access; staff never get direct write credentials.

**Compensation.** One endpoint covers forfeit waivers and refunds for failed service; staff choose who pays.

| `charge_to` | When | Ledger effect |
| --- | --- | --- |
| `platform` | Goodwill: emergency, retention, Dorsia's own error | Dorsia's account pays |
| `venue` | Venue at fault: closed, overbooked, cancelled on the member | Taken from venue_payable; if already paid out, netted from the next payout |

- `amount` is capped at what the member actually lost on that reservation (the forfeit, or the full minimum if the venue failed to seat them).
- `reason_code` comes from a fixed list per `charge_to`, so venue charges can be reported on.
- Venue charges show as line items on the venue's payout statement. If the venue objects, its account manager handles it; if Dorsia agrees, staff post a second compensation moving the charge to `platform`. No dispute workflow; the audit log records both.

**Pricing and demand signals.** Pricing has two halves, both inside the Pricing module.

| Half | Runs | Does |
| --- | --- | --- |
| Demand signals | Scheduled Laravel job every 15 minutes | Aggregates sell-out speed per venue, weekday and hour, and unmet searches and custom-request bids per party-size band, into `demand_signals` |
| Quote | Live, on each hold | Reads the latest signals for the hold's party-size band, computes the per-person minimum, locks it to the hold |

- **Unmet search.** A search is unmet when the venue has no open slot at the requested hour, or has one but not enough capacity left for the requested party size. Searches are counted per party-size band (1–2, 3–4, 5+), so if tables for 2 are open but tables for 4 are sold out, demand rises for the 3–4 band only.
- **Bands, not exact sizes.** Bands keep each row's counts large enough to trust; set them to match the venues' real table shapes. Sell-out speed is a property of the slot, so it is not split by band.
- **Thin bands fall back.** If a band has fewer than 5 unmet searches in the window, the quote uses the venue-and-hour total instead.
- **Searches live in Postgres, not analytics.** Pricing depends on them, the backend sees every search while client analytics drops some, and BI never feeds the backend. Rows older than 90 days are pruned; copy them to analytics through the outbox if needed.
- **Deduplication.** A member counts at most once per venue, date, hour and party size, however many times they search or refresh. A few members refreshing the page can't push the price up.
- The quote never waits on the job; stale signals mean a slightly stale price, never a failed booking.
- The BI tool only reads `price_quotes` and `demand_signals` so humans can compare predicted demand with actual sell-through and tune the rules. Nothing it produces feeds back into the backend.

---

## 3. Data model

Postgres is the source of truth, and the database itself enforces the two rules that matter: no overbooking and balanced money.

| Table | Key columns | Constraint that protects us |
| --- | --- | --- |
| venues | city_id, timezone, status | Timezone stored per venue, never inferred |
| slots | venue_id, starts_at, capacity, booked_count | CHECK booked_count <= capacity |
| holds | slot_id, member_id, expires_at, idempotency_key | Unique idempotency_key; expires in 5 minutes |
| search_events | member_id, venue_id, requested_at, party_size, had_availability | Unique (member_id, venue_id, requested_at hour, party_size); repeats are dropped, which deduplicates searches |
| demand_signals | venue_id, weekday, hour, party_band, sellout_seconds_p50, unmet_searches, bid_count, computed_at | One row per venue, weekday, hour and party band; sellout_seconds_p50 is the same across bands; overwritten by the job |
| price_quotes | slot_id, party_size, per_person_cents, amount_cents, inputs (jsonb), expires_at | Quote locked to the hold; CHECK amount_cents = per_person_cents * party_size; inputs kept for audit |
| reservations | member_id, slot_id, party_size, min_spend_cents, status, idempotency_key | Unique idempotency_key; status is a state machine |
| members, memberships | tier, period_end, status | One active membership per member |
| ledger_accounts | owner, kind (cash, credit, coupon, venue_payable, platform) | One account per owner and kind |
| ledger_transactions | type, reference, idempotency_key | Unique idempotency_key |
| ledger_entries | transaction_id, account_id, amount_cents | Append-only; entries per transaction sum to zero |
| coupon_lots | account_id, amount_cents, expires_at | Spend oldest-expiring first |
| admin_audit_log | staff_user_id, action, subject, reason, payload, idempotency_key | Append-only; written in the same transaction as the change |
| outbox_events | topic, payload, published_at | Written in the same transaction as the change; partial index on unpublished rows |
| payments | provider, provider_ref, status | Unique provider_ref, so webhooks are idempotent |

**Conventions.** Money is integer cents with a currency column. Times are UTC, displayed in the venue's timezone. Nothing financial is ever updated or deleted; corrections are new entries.

---

## 4. Booking under contention

Two layers stop overbooking: a Redis counter sheds the stampede cheaply, and a conditional Postgres update is the final word.

```mermaid
flowchart TB
    S1["1. Request with idempotency key<br/>Retries with the same key return the first result"]
    S2["2. Redis gate<br/>Atomic DECR on a slot counter rejects losers before the database"]
    S3["3. Hold in Postgres<br/>Conditional UPDATE: held + booked under capacity; 5-minute expiry"]
    S4["4. Price quote<br/>Minimum spend locked to the hold, inputs saved for audit"]
    S5["5. Pay<br/>Wallet balance first, then gateway authorize for the rest"]
    S6["6. Confirm in one transaction<br/>Hold becomes reservation, ledger posts, outbox event written"]
    S7["7. Broadcast<br/>Worker pushes slot.booked and reservation.confirmed"]
    SO["Sold out<br/>Counter below zero: fast 409"]
    RL["Release hold<br/>Payment fails or hold expires<br/>Slot and counter restored"]

    S1 --> S2
    S2 -->|empty| SO
    S2 --> S3 --> S4 --> S5
    S5 -->|fails| RL
    S5 --> S6 --> S7
```

If Redis and Postgres ever disagree, Postgres wins and the counter is rebuilt from it; Redis only makes losing fast.

- **Counter rebuild.** A missing or out-of-sync counter is rebuilt under a lock as capacity minus booked minus active holds.
- **Hold expiry.** Enforced lazily on read and confirm, and swept every minute by the scheduler. Both paths restore the counter and write `slot.released` to the outbox.
- **Idempotency key reuse.** The same key with the same body replays the first result; the same key with a different body is rejected with 422.

---

## 5. Wallet ledger

Every money movement is a balanced double-entry transaction, so a balance is always the sum of entries and can never drift.

Examples use a table for 2 at a reported $500 per person ($1,000). Real minimums are dynamic per slot and come from the locked price quote; no amount is hard-coded.

| Event | Debit | Credit | Example ($) |
| --- | --- | --- | --- |
| Book with card | card clearing | venue_payable | 1,000 |
| Book with coupons | member coupon | venue_payable | 1,000 |
| Cancel 8+ hours out | venue_payable | member credit | 1,000 |
| Overage at end of meal | card clearing | venue_payable | 300 |
| Cancel inside 8 hours | venue_payable 750 | member credit 500, platform 250 (the other 250 stays owed to the venue and goes out in its next payout; split assumed) | 1,000 |
| Coupon grant (Premium) | platform | member coupon | 333 |
| Coupon grant (Premium Plus) | platform | member coupon | full annual balance, one lot |
| Coupon lot expiry (90 days Premium, 12 months Plus) | member coupon | platform | remaining lot |
| Membership ends | member coupon | platform | all remaining lots |
| Venue payout | venue_payable | external (Stripe Connect) | 1,000 |
| Staff goodwill credit | platform | member credit | 50 |
| Compensation, `charge_to=platform` | platform | member credit | 500 (waives the late-cancel forfeit) |
| Compensation, `charge_to=venue` | venue_payable | member credit | 500 (venue at fault) |

**Rules.**

- A transaction posts only if its entries sum to zero; a Postgres trigger rejects anything else.
- Balances are a cached projection, rebuilt from entries; the entries are the truth.
- Spend order is coupons nearest expiry first, then credit, then card.
- A nightly job re-sums every account and alerts on any difference from the cache.
- No-shows and underspend post nothing: the full minimum is already in venue_payable at booking, and the venue keeps it.

The late-cancel split is a guess, so it lives in config, not code; Dorsia publishes the 50% forfeit, not who keeps it. The FAQ also says the member's 50% returns to the "Dorsia Wallet" rather than as dining credit; we post it as member credit until the difference is known.

---

## 6. Real-time and payments

Real-time pushes are a convenience layered on the database, and payments sit behind an interface, so neither can corrupt a booking.

**Real-time.** Laravel Reverb locally; Pusher in production via the same Laravel broadcasting driver, a config switch.

| Channel | Events | Who listens |
| --- | --- | --- |
| venue.{id}.slots | slot.held, slot.released, slot.booked | Anyone viewing that venue |
| drop.{id} | drop.opened, drop.sold_out | Members waiting on a drop |
| private-member.{id} | hold.expiring, reservation.confirmed, wallet.updated | That member only |

- Events leave through the outbox, so a broadcast never fires for a transaction that rolled back.
- The app treats pushes as hints and re-fetches state; a missed event costs freshness, never correctness.
- **Drop-ins.** A table can reopen at any moment: a hold expires, a member cancels, or a venue releases extra capacity. Each one restores the Redis counter and writes `slot.released` to the outbox in the same transaction as the Postgres change. Members not viewing the venue hear about it through a push notification, sent from the same outbox event *(inferred: "inventory drop-in" in a Dorsia engineer's profile)*.
- **Who hears first.** Notifications go to members with an unmet search for that venue and hour whose party band fits the reopened capacity, ordered by membership tier, shuffled within each tier. Everyone else gets a general alert after the targeted members. Early access is a stated tier perk, not a hidden ranking.

**Payments (stubbed).** One `PaymentGateway` interface with `authorize`, `capture`, `refund` and `payout`. A `FakeGateway` drives tests and can simulate declines, timeouts and duplicate webhooks. `StripeGateway` drops in later with no domain changes.

---

## 7. Infra and deployment

Run everything in Docker locally and match Dorsia's production shape, Laravel on AWS Lambda, so the lessons transfer directly.

| Concern | Local | Production |
| --- | --- | --- |
| API | Laravel Octane in Docker | Laravel Vapor (Lambda) or Laravel Cloud |
| Database | Postgres 16 | RDS Postgres + RDS Proxy (Lambda opens many connections) |
| Cache and locks | Redis 7 | ElastiCache Redis |
| Queues | Redis queue | SQS via Vapor |
| Real-time | Reverb | Pusher |
| Edge | None | Cloudflare (WAF, rate limits, bot filtering on drops) |
| Infra as code | docker-compose | Terraform for anything Vapor doesn't manage |
| CI/CD | GitHub Actions: tests, static analysis | Same, plus deploy on merge to main |
| Observability | Laravel Telescope | Datadog APM, logs, alerts on p95 and ledger drift |

**Why serverless fits.** Traffic is quiet most of the day and spikes on drops; Lambda scales out in seconds and costs nothing at idle. The watch-out is database connections, hence RDS Proxy.

**Pre-warm before drops.** A drop is the one moment Lambda has to start hundreds of fresh instances at once, and a cold Laravel instance takes about 0.5–2 seconds to answer. In a race for a few tables, that delay decides who wins. Drops are scheduled, so a scheduled job raises provisioned concurrency a few minutes before each drop and lowers it after.

Drop-ins can't be scheduled. A push notification for a table that just opened sends a spike with no warning, so keep a small baseline of provisioned concurrency warm at all times during dinner-booking hours. Targeting only members who searched for that slot keeps most sends small; larger ones go out tier by tier, which also flattens the spike.

**Drop protection at the edge.** Per-member rate limits, a virtual waiting room for the largest drops, and bot filtering. Most of the fairness fight happens before the API.

---

## 8. Maintainability

One deployable with hard module boundaries: the speed of a monolith now, and clean seams to split into services only if a module outgrows it.

**Module boundaries.**

- Each module (Inventory, Pricing, Reservations, Wallet, Memberships, Integrations) lives in `app/Modules/<Name>` with its own models, actions, events and tests.
- Modules talk through public action classes and domain events, never each other's tables.
- An architecture test (Pest arch) fails the build if one module imports another's internals.

**Code conventions.**

- One action class per use case (`HoldSlot`, `ConfirmReservation`, `PostLedgerTransaction`), so business logic is easy to find and test.
- Controllers stay thin: validate, call an action, return a resource.
- Strict types, PHPStan at level 8, Pint for formatting.

**Testing pyramid.**

- Unit tests for pricing math and ledger rules.
- Feature tests per endpoint against a real Postgres, not SQLite, so locks behave as in production.
- Concurrency tests that fire parallel requests at one slot.
- The k6 drop test before any release that touches booking.

---

## 9. Load test and build order

The build is done when a simulated drop sells exactly 50 tables to 50 people with balanced books.

**Drop test (k6).** 5,000 virtual members, 50 tables, 10-second ramp, each member retrying once with the same idempotency key.

| Check | Pass condition |
| --- | --- |
| Reservations confirmed | Exactly 50 |
| Double bookings | 0 |
| Duplicate charges | 0 |
| Ledger sum across all accounts | 0 |
| Booking p95 latency | Under 300 ms |
| Losers' experience | Clear sold-out response, no 500s |

**Build order.**

1. Skeleton: Laravel, Docker (Postgres, Redis, Reverb), CI, architecture tests.
2. Inventory and slots, with seeded venues across timezones.
3. Hold and confirm flow, with locks and idempotency, plus concurrency tests.
4. Wallet ledger with the balance trigger and nightly reconciliation.
5. Pricing engine, starting rule-based (demand tiers by day and time), then the demand-signals job once there is booking history.
6. Fake payment gateway and webhook handling.
7. Real-time channels via the outbox.
8. k6 drop test; fix until it passes.
9. Memberships and coupon grants and expiry.
10. Admin API with staff auth, audit log and permissions.
10. Expo app on top of the proven API.