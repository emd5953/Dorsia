# 02 — Inventory and holds

| | |
| --- | --- |
| Owner | Booking |
| Depends on | 01 merged into `build/v1` |
| Parallel with | 03, 04, 05 |
| Blocks | 06, 08 |
| Branch | `feat/inventory-holds` off `build/v1` |
| Worktree env | `bin/worktree-env 02` → `dorsia-02`, ports 8200 / 8280 / 5632 / 6579 |
| Build plan | §3 (`venues`, `slots`, `holds`), §4 steps 1–3, counter rebuild, hold expiry, idempotency reuse; §6 drop-ins (counter restore + `slot.released`), drop channel events; §9 step 2 and the hold half of step 3 |
| Contracts | C§3 Inventory tables, C§5.1 (all), C§5.6 `PlaceHold` + `GetHoldView` (quote-less version), C§6.1, C§7 rows written by Inventory, C§8 Inventory endpoints + `POST/GET /api/holds`, C§9 Inventory codes, C§12 `inventory.*`, C§13 Inventory commands, D1, D2, D18, D19, D21, D24, D28 |

## Files you may touch

- `app/Modules/Inventory/**` (including the three stubs Platform created; keep their signatures)
- `app/Modules/Reservations/**` — only: `routes/api.php`, `Actions/PlaceHold.php`, `Actions/GetHoldView.php`, `Data/HoldView.php`, `Http/Controllers/HoldController.php`, `Http/Requests/PlaceHoldRequest.php`, `Http/Resources/HoldResource.php`. Inventory's `SlotResource` is internal, so `HoldResource` builds the C§8 `Slot` shape itself from `SlotData`.
- `tests/Modules/Inventory/**`, `tests/Modules/Reservations/Feature/Hold*`, `tests/Modules/Reservations/Concurrency/Hold*`
- `tests/Arch/Contracts/InventoryContractTest.php`

## Non-goals

Pricing quotes (the hold's `quote` is `null` until ticket 06), confirm, cancel, payments, push notifications to members not viewing the venue (D20), member tiers, admin `ReleaseSlot`, venue sync. Do not broadcast anything; only write outbox rows.

## Work

1. Migrations (prefix `2026_10_02_`): `cities`, `venues`, `drops`, `slots`, `holds` with every column, CHECK, unique and partial index in C§3.
2. Models + factories for all five tables.
3. `Support\SlotGate`: Lua acquire / release, rebuild under `Cache::store('redis')->lock`, key names and TTL per C§6.1.
4. Public actions, DTOs, enums, exceptions, event exactly per C§5.1. `ConvertHoldToBooking` and `ReleaseBooking` are built and tested here even though 06 is their first caller.
5. Internal `Support\ExpireOverdueHolds` (used by `HoldSlot` and the sweeper).
6. Endpoints (Inventory routes): `GET /api/cities`, `/api/venues`, `/api/venues/{venue}`, `/api/venues/{venue}/availability`, `/api/slots/{slot}`, `/api/drops/{drop}` per C§8, with resource shapes exactly as listed. Availability dispatches `AvailabilitySearched` with venue-local date/hour.
7. Reservations side: `PlaceHold` calls `HoldSlot` with no `$inTransaction` callback for now and returns `HoldView(hold, null)`; `GetHoldView`; `POST /api/holds` (middleware `auth:sanctum`, `idempotent`) → 201; `GET /api/holds/{hold}`.
8. Commands + schedule: `inventory:expire-holds`, `inventory:open-drops` (C§13).
9. `Database/Seeders/InventorySeeder`: 4 cities (New York `America/New_York`, Chicago `America/Chicago`, Los Angeles `America/Los_Angeles`, Honolulu `Pacific/Honolulu`), 2 venues each (USD, `base_per_person_cents` between 15000 and 30000), and for each venue for the next 14 local days at local 18:00, 19:00, 20:00, 21:00, 22:00 three slots: 1–2 seats ×6, 3–4 seats ×4, 5–6 seats ×2, `released_at` = now. Plus one future drop at one NYC venue opening tomorrow 12:00 local with its own three 20:00 slots. Deterministic (fixed names/slugs), re-runnable on `migrate:fresh --seed`.

## Acceptance criteria

- [ ] All C§3 Inventory constraints exist in the database: inserting `held_count + booked_count > capacity` directly via SQL fails; a second `active` hold for the same member and slot fails; duplicate `idempotency_key` fails.
- [ ] `POST /api/holds` on an open slot → 201 `Hold` JSON (C§8 shape, `quote: null`), `held_count` +1, one `slot.held` outbox row with the C§7 payload and correct `available`.
- [ ] Gate key `dorsia:slot_gate:{id}` exists after the first hold and equals `capacity - held - booked`.
- [ ] Capacity reached → 409 `sold_out` with **no** DB write (assert query count or no new rows) when the gate is warm.
- [ ] Gate missing (Redis flushed) → rebuilt from Postgres on next hold, value correct, hold succeeds.
- [ ] Gate too high (manually `SET` to 99 while the slot is full) → 409 `sold_out`, `held_count` unchanged, gate rebuilt to 0.
- [ ] Gate too low (manually `SET` to 0 while the slot has room) → `sold_out` until the sweeper runs; after `inventory:expire-holds` the gate is rebuilt and holds succeed (documents D19 bound).
- [ ] Party size outside `[min, max]` → 422 `party_size_not_allowed` with `details.min_party_size/max_party_size`; no gate unit consumed.
- [ ] Slot in the past or venue inactive → 409 `slot_unavailable`; slot in an unopened drop → 409 `drop_not_open`; at exactly `released_at` the hold succeeds.
- [ ] Unknown slot → 404 `not_found`.
- [ ] Hold expiry: at `expires_at - 1s` `GET /api/holds/{id}` shows `active`; at `expires_at` exactly it shows `expired`, `held_count` restored, gate +1, one `slot.released` row with `reason: "expired"`.
- [ ] Sweeper expires overdue holds without any read, writes `slot.released` once per hold (running it twice writes nothing new), and writes `hold.expiring` exactly once per hold within 60 s of expiry.
- [ ] `HoldSlot` on a slot that is full only because of overdue (unswept) holds succeeds by expiring them first.
- [ ] `ReleaseHold` releases once and is a no-op the second time (no second outbox row, no double gate increment).
- [ ] `ConvertHoldToBooking`: converts, `held -1`, `booked +1`, `slot.booked` outbox row, sets `sold_out_at` when full; on the last slot of a drop sets `drops.sold_out_at` and writes `drop.sold_out` once; rejects expired (at `expires_at` exactly), non-active, and other members' holds; throws `LogicException` outside a transaction.
- [ ] `ReleaseBooking` decrements, writes `slot.released` (`reason: "cancelled"`), increments the gate after commit only (rolled-back transaction → gate unchanged).
- [ ] `inventory:open-drops` sets `opened_at` and writes one `drop.opened` with `slot_ids`; second run writes nothing.
- [ ] Availability: returns all slots of the venue-local date ordered by `starts_at`; `had_availability` follows C§8's definition; dispatches exactly one `AvailabilitySearched` with venue-local date/hour (test a Honolulu venue and a New York venue across the US DST change on 2026-11-01).
- [ ] `GetSelloutStats` returns venue-local weekday/hour and the median sell-out seconds (test with odd and even sample counts).
- [ ] Hold replay: same key + body → identical 201 body, `Idempotent-Replayed: true`, no second hold, no second outbox row, gate unchanged; same key different `party_size` → 422 `idempotency_mismatch`.
- [ ] Seeder produces the listed cities/venues/slots/drop; running `migrate:fresh --seed` twice gives identical counts.
- [ ] Gates: `make ci` green; `make arch` green; `InventoryContractTest` covers every C§5.1 item.

## Required tests

- Unit: `SlotGate` Lua semantics against real Redis (acquire to zero, sold-out leaves value at 0 not −1, release on missing key does nothing), `SlotData::available()`.
- Feature: one test per acceptance bullet above (`tests/Modules/Inventory/Feature/*`, `tests/Modules/Reservations/Feature/Hold*`).
- Concurrency (`tests/Modules/Reservations/Concurrency/HoldRaceTest.php`, `tests/Modules/Inventory/Concurrency/*`), each with ≥ 20 parallel processes:
  - 40 members race a slot with `capacity = 5` → exactly 5 × 201, 35 × 409 `sold_out`, zero 5xx; DB `held_count = 5`, 5 active holds, gate = 0.
  - Same race with the gate key deleted just before firing → still exactly 5 holds, gate rebuilt to 0.
  - 20 parallel identical requests from one member with one key → exactly 1 hold; responses are the 201 or 409 `idempotency_in_progress`; zero 5xx.
  - 20 parallel requests from one member with *different* keys on one slot → exactly 1 hold, the rest 409 `active_hold_exists` or `sold_out`, gate consistent with DB afterwards.
  - Sweeper expiring holds while 20 new members race the freed tables → `held_count + booked_count <= capacity` always, final gate equals DB truth.
- `tests/Arch/Contracts/InventoryContractTest.php`.

## Eval agent will check

- Every C§3 Inventory CHECK/unique/partial index exists (`\d+` on each table).
- Traces each §4 rule (gate, conditional update, 5-minute expiry, counter rebuild, lazy + swept expiry restoring counter and writing `slot.released`, idempotency replay/422) to code and a named test.
- Reads `HoldSlot` for: validation before gate, gate release on every failure path after acquire, no INCR on the Postgres-rejected path, no SQL `now()`.
- Boundary cases: capacity = booked, `expires_at` exactly, `released_at` exactly, party size at min and max, DST week.
- Runs the concurrency suite 3 times; any flake fails the round.
- Confirms nothing from the non-goals (no quote code, no broadcasting).
