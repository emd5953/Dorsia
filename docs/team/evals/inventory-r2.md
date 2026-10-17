# Inventory and holds (ticket 02): eval round 2

**Verdict: FAIL, 83/100** (Correctness 35, Edge cases 21, Concurrency 15, Code quality 12). Gates pass: `make ci` 525 tests; arch catches violations; concurrency 3/3. Round-1 defects 3 (HoldView `?QuoteData`) and 4 (M06) fixed.

Attacks (invariant monitor, ~260,000 checks, 2 runs): zero overbooking, zero counter drift. Drop-in, crash and idle-slot cases now heal (A5 ~5.1 s, A6 ~5.1 s, A7 immediately). Refresh storms: 1 rebuild per window. A9 (one member with an overdue hold, 20 keys): deadlock 5xx in 4 of 5 runs (round 1: 0 of 5).

Mutation testing: 37 mutants, 27 killed. Survivors: R01 guard without NX, R02 guard without TTL, R03 guard TTL 60 s, G01–G03 generation check, H04 INCR on the Postgres-rejected path, H11 rejected path rebuilding without the guard; H10, S03 near-equivalent.

Defects:
1. Major: deadlock between the refresh rebuild expiring overdue holds (HoldCloser locks hold then slot, `HoldCloser.php:28`, `:49`) and HoldSlot::attempt (slot lock at `HoldSlot.php:201`, INSERT waiting on the overdue hold via the one-active-hold unique index). SQLSTATE 40P01 → 500; the victim can be another member. Fix: lock slot before hold in HoldCloser::close; retry 40P01 once in place().
2. Major: refresh guard barely tested (R01–R03 survive); no test asserts no rebuild/overdue query inside the window, nor guard expiry.
3. Minor: a crashed request's unit heals only after the `:pending` TTL (~5.1 s), not on the next request; `HoldSlotTest.php:333-343` pre-sets the guard and sleeps. The `:pending` counter isn't in the contract.
4. Minor: generation check (`HoldCloser.php:60-61`, `ReleaseBooking.php:46-47`) can skip a release and leave a freed table sold out until the next refresh; untested.
5. Minor: contract config `inventory.gate.rebuild_wait_ms` (200) missing; losers poll up to 5 s.
6. Minor: Redis flush between refresh and second acquire (`HoldSlot.php:139-146`) reports a unit as taken; gate ends too high (safe).

Eval harness and mutants: `inventory-r2-tools/`.
