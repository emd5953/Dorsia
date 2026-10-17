# Inventory and holds (ticket 02): eval round 5 (after architect takeover)

**Verdict: PASS, 93/100** (Correctness 39, Edge cases 23, Concurrency 18, Code quality 13). `make ci` green from a fresh stack (916 tests); arch catches 5/5 planted violations; per-key lock, step 2b and one-active retry match C§6.1. All round-4 defects fixed and caught when mutated; N10, N15, S03 killed. `HoldRaceTest` + Inventory concurrency group 25/25 under 6 CPU-hog containers.

Attacks (3 runs, A1–A11, A9b, R1–R4, A10r/s, H1/H2, lock attacks L1–L5): zero invariant violations, zero non-domain exceptions, gate never too low after the refresh window; lock holder killed mid-transaction → retry proceeds after 5 s; abandoned lock and holder past TTL → replays, no leaked unit.

Open (follow-up):
1. Minor: on lock-wait timeout, same-key callers can get SoldOut/ActiveHoldExists instead of a replay (`HoldSlot.php:95-97`, `:103-107`); gate ends too high (safe). Replay before throwing on both paths.
2. Minor (tests): lock name, TTL, wait-timeout not pinned (K03, K04, K06, K07, K09, C01, C03 survive). K04 (one global lock) would serialise a drop; ticket 08's p95 would show it. Add a different-keys-in-parallel test.
3. Minor: `InventoryContractTest` doesn't assert `inventory.hold_key_lock.seconds`/`wait_seconds`.
4. Nit: own-hold expiry boundary at exactly `expires_at` untested (P04).
