# Inventory and holds (ticket 02): eval round 1

**Verdict: FAIL, 87/100** (Correctness 34, Edge cases 21, Concurrency 18, Code quality 14). Gates pass: `make ci` 506 tests; arch 83 and catches a Wallet import; schema matches C§3 (all CHECKs, uniques, partial indexes, timestamptz(6)); concurrency 15/15 x3.

Zero overbooking under every attack (a monitor checked held+booked<=capacity and counter consistency ~15,000 times per run): 70 racers on 50 tables; 40 on 1; Redis flushed mid-drop; gate forced to 999 while full; release racing convert; one member with an overdue hold on 20 keys.

Mutation testing: 37 mutants, 36 killed. Survivor M06: moving HoldCloser's gate INCR before commit (`HoldCloser.php:60`).

Defects:
1. Major: the gate ignores overdue holds (`SlotGate.php:99`, `HoldSlot.php:114`). Rebuild uses `capacity - booked - held_count` with overdue holds still counted, and a warm gate at 0 sheds before Postgres can expire them, so freed tables stay sold out up to a minute in normal running. Breaks §6 drop-ins. The existing acceptance test only passes because it sets the gate to 1 first (`HoldSlotTest.php:316-317`).
2. Major: a too-low gate is healed only if the slot was touched recently (`RebuildRecentGates.php:24-27`). A crash after DECR and before commit doesn't touch `updated_at`, so the slot stays sold out until the key's TTL (starts_at + 1 day). Literal C§6.1, but breaks D19's one-sweep bound.
3. Minor: `HoldView.php:14` types `$quote` as `null`; C§5.6 says `?QuoteData`, which exists on build/v1.
4. Minor: no rollback test for HoldCloser's after-commit gate release (M06).
