# 04 — Pricing engine

| | |
| --- | --- |
| Owner | Pricing |
| Depends on | 01 merged (uses Platform's Inventory contract stubs) |
| Parallel with | 02, 03, 05 |
| Blocks | 06 |
| Branch | `feat/pricing` off `build/v1` |
| Worktree env | `bin/worktree-env 04` → `dorsia-04`, ports 8400 / 8480 / 5832 / 6779 |
| Build plan | §2 "Pricing and demand signals" (all bullets), §3 (`search_events`, `demand_signals`, `price_quotes`), §4 step 4, §9 step 5 |
| Contracts | C§3 Pricing tables, C§5.2, C§10, C§12 `pricing.*`, C§13 Pricing commands, D11, D12, D13 |

## Files you may touch

- `app/Modules/Pricing/**`
- `tests/Modules/Pricing/**`, `tests/Arch/Contracts/PricingContractTest.php`

You consume `Inventory\Events\AvailabilitySearched`, `Inventory\Data\SelloutStat`, `Inventory\Actions\GetSelloutStats` (stubs from ticket 01; Booking implements `GetSelloutStats` in parallel). In tests, mock `GetSelloutStats` with `$this->mock(GetSelloutStats::class)` and dispatch `AvailabilitySearched` directly.

## Non-goals

Custom requests and bids (`bid_count` stays 0), exporting searches to analytics, BI access roles, membership-based pricing, the availability endpoint itself (Inventory).

## Work

1. Migrations (prefix `2026_10_03_`): `search_events`, `demand_signals`, `price_quotes` with every constraint and index in C§3.
2. Models + factories.
3. `Support\PartyBands` (config-driven), `Support\PriceCalculator` (pure: inputs → per-person cents + `inputs` array, C§10 steps 1–5).
4. Public actions per C§5.2: `QuoteHold`, `GetQuoteForHold`, `RecordSearch`, `ComputeDemandSignals`, `PruneSearchEvents`; DTOs `QuoteHoldInput`, `QuoteData`; `QuoteNotFoundException`.
5. Listener `RecordAvailabilitySearch` registered via the provider's `listeners()` hook; swallows and logs errors.
6. Commands + schedule: `pricing:compute-demand-signals` (every 15 min), `pricing:prune-search-events` (daily 04:00 UTC).
7. `config.php` with every `pricing.*` key and default from C§12/C§10.

## Acceptance criteria

- [ ] `PriceCalculator` reproduces C§10's example: base 25000, Saturday 20:00 local, no signals → 37500 per person; party of 2 → 75000.
- [ ] Tier boundaries (venue-local): Fri 19:00 peak; Fri 18:00 shoulder; Fri 22:00 shoulder; Fri 23:00 off-peak; Thu 19:00 shoulder; Mon 20:00 off-peak; Sun 22:00 shoulder; a slot at 01:00 UTC Saturday that is Friday 20:00 in New York is priced as Friday peak.
- [ ] Thin band: band unmet 4 with venue-hour total 12 → `unmet_used = 12`, `used_fallback = true`; band unmet 5 → uses 5, `used_fallback = false`.
- [ ] Unmet cap: 80 unmet searches → `unmet_bps = 5000`, not 8000.
- [ ] Sell-out: p50 45 s → +2500; 60 s → +2500; 61 s → +1000; 600 s → +1000; 601 s → 0; null → 0.
- [ ] Rounding to whole dollars half-up and `min_per_person_cents` floor both covered.
- [ ] `inputs` jsonb contains exactly the C§10 keys.
- [ ] `QuoteHold` inserts one row with `amount_cents = per_person_cents × party_size` and `expires_at` = hold's; a second call for the same `hold_id` returns the same row; throws `LogicException` outside a transaction; DB CHECK rejects a mismatched amount via raw SQL.
- [ ] `QuoteHold` with no `demand_signals` rows succeeds with `demand_bps = 10000` (quote never waits on the job).
- [ ] `RecordSearch`: stores venue-local date/hour, `requested_at` UTC, ISO weekday, band; the same member/venue/date/hour/party size twice → one row (second returns false); different party size → second row; Asia/Kolkata (+05:30) venue in a unit-level test stores the right local hour.
- [ ] Listener failure (e.g. DB error simulated) does not throw out of `event(new AvailabilitySearched(...))`.
- [ ] `ComputeDemandSignals`: counts only `had_availability = false` within the 28-day window (a 29-day-old row is excluded), grouped per band; sell-out p50 from `GetSelloutStats` copied to **every** band row of that venue/weekday/hour; `bid_count = 0`; stale combos from a previous run deleted; running twice yields identical rows with the new `computed_at`.
- [ ] The §2 example: tables for 2 open but tables for 4 sold out → searches for party 4 raise only the `3-4` band's `unmet_searches`.
- [ ] `PruneSearchEvents` deletes rows with `created_at` older than 90 days only.
- [ ] Gates: `make ci`, `make arch`, `PricingContractTest`.

## Required tests

- Unit (`tests/Modules/Pricing/Unit`): `PriceCalculatorTest` (all tier, fallback, cap, sell-out, rounding and floor cases above, as a dataset), `PartyBandsTest` (1, 2, 3, 4, 5, 20).
- Feature: `QuoteHoldTest`, `RecordSearchTest`, `ComputeDemandSignalsTest`, `PruneSearchEventsTest`, `ListenerTest`.
- Concurrency: 20 parallel `RecordSearch` calls with the identical tuple → exactly one row, no exception; 20 parallel `QuoteHold` for the same `hold_id` → exactly one `price_quotes` row.
- Idempotency: `QuoteHold` replay (above); signals job idempotent re-run (above).
- `tests/Arch/Contracts/PricingContractTest.php`.

## Eval agent will check

- Every §2 pricing bullet traced to code and a test: bands, thin-band fallback threshold 5, dedupe, 90-day pruning, quote never waits, sell-out speed not split by band, signals overwritten by the job.
- Integer-only math in `PriceCalculator` (no floats; `grep` for `float`, `round(`, `/` on money).
- Timezone handling: weekday/hour derived in venue timezone, never server time.
- The job reads Inventory only through `GetSelloutStats` (arch test plus `grep` for `slots`).
- Concurrency tests run 3 times.
