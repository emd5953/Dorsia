# Inventory and holds (ticket 02): eval round 4

**Verdict: FAIL, 89/100** (Correctness 38, Edge cases 22, Concurrency 16, Code quality 13). Fourth failed round → architect takes over (process.md).

Gates: `make ci` green from a fresh `make down && make up` (883 tests); arch catches 5/5 planted violations; only three gate keys; `rebuild_wait_ms`/`refresh_seconds` asserted; A10 fix (FOR SHARE, SET before commit) and deadlock pins hold.

Flakiness: concurrency group 10/10, full suite 3/3 unloaded; under CPU load `HoldRaceTest.php:69` failed 4/25 (gate 1–2, expects 0).

Attacks (3 runs, A1–A11, A9b, R1–R4, two-process A10): zero overbooking, zero 5xx, gate never below truth; heal 5.05–5.07 s when guard held; A10 10/10.

Mutation: 63 mutants, 56 killed. Real survivors: N10 (`HoldSlot.php:191` expiring with `releaseGate:false`), N15 (`HoldSlot.php:84` replay after unique violation), S03 (rebuild-lock double-check).

Defects:
1. Major: flaky `HoldRaceTest.php:69`, `:93` assert gate == 0, which C§6.1 doesn't guarantee (refresh SET overwrites in-flight decrements). Assert gate ≥ Postgres truth.
2. Minor: A9b fix covers only a live own hold (`HoldSlot.php:68`, `:247-255`); with an overdue own hold, parallel requests still take units (5/30 runs others told sold_out with a free table). Expire own overdue hold first.
3. Minor (likely): `HoldSlot.php:163` — if a sibling already expired the overdue hold, `ActiveHoldExistsException` is raised without retry.
4. Minor: 12 parallel action-level calls with one idempotency key: 7 SoldOut instead of replay; gate 6 on capacity 4 (contradicts C§6.1 step 5; HTTP middleware hides it).
5. Minor: no tests kill N10, N15, S03; no action-level same-key race test.
