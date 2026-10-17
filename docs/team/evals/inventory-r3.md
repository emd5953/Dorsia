# Inventory and holds (ticket 02): eval round 3

**Verdict: FAIL, 87/100** (Correctness 37, Edge cases 22, Concurrency 16, Code quality 12).

Gates: `make ci` green only because of an untracked `memory_limit=512M` ini added inside the container (suite crashes at 128 MB) — fixed on build/v1 via `phpunit.xml` (5720434). Arch bites. `:pending` and generation code fully removed; only counter and `:refresh` keys in Redis (`:lock` released); `rebuild_wait_ms` 200.

Attacks (~272,000 invariant checks, 3 runs): zero overbooking; A9/A9b 60 runs, zero 5xx, zero deadlocks; drop-in/crash heal on the first request (5.03–5.11 s when the guard is held). A10 (rebuild racing a release): gate 0 with a free table for up to 5 s. A9b: 9 of 30 runs briefly told other members sold out while tables were free.

Mutation testing: 48 mutants, 36 killed. Real survivors: L02 (HoldCloser not locking slot first) and D01/D03/D04 (40P01 retry removed/wrong) mask each other; L03 (ConvertHoldToBooking locks hold before slot); W02 (`rebuild_wait_ms` 5000 not caught). With L02+D01 the deadlock returns; `DeadlockRaceTest` caught it in only 2 of 6 runs.

Defects:
1. Major (gate): memory limit only in the container. Fixed on build/v1.
2. Major: deadlock fix not pinned by a reliable test (`HoldCloser.php:39`, `HoldSlot.php:165-171`, `DeadlockRaceTest.php:39-74`). Need a deterministic 40P01 retry test and a lock-order test.
3. Minor: rebuild can make the gate too low (contradicts C§6.1): reads slot at `SlotGate.php:104`, a release committing and INCRing before the SET at `:113` is overwritten. Read `FOR SHARE` in a transaction, SET before commit.
4. Minor: a member firing parallel holds with different keys takes gate units (`HoldSlot.php:66`) before the duplicate is found, briefly showing sold out to others. Check own active hold first.
5. Minor: `InventoryContractTest.php:182-189` doesn't check `rebuild_wait_ms` or `refresh_seconds`.
6. Minor: `GateRefreshTest.php:278-289` "counter vanishes" never flushes Redis.
7. Note: `HoldRaceTest` relaxed to "gate ≥ DB truth" (matches C§6.1; ticket text says "equals").
