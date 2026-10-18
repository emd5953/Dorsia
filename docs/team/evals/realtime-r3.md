# Real-time via the outbox (ticket 07): eval round 3

**Verdict: PASS, 91/100** (Correctness 37, Edge cases 21, Concurrency 20, Code quality 13). `make ci` green (646 tests); arch 77 and bites; SKIP LOCKED asserted; no broadcasting outside Realtime. Every round-2 fix is caught by a test when mutated. PayloadShape matches C§7 for every topic; real producers (`wallet.updated` on build/v1; Inventory rows on feat/inventory-holds incl. `available: 0`) pass. BatchOutcome singleton safe under Octane.

Probes: poison rows (unknown topic, missing/mistyped keys, JSON list/scalar) dead-lettered once with one critical; Postgres down 20 s → survived, resumed within 11 s; Reverb down 62 s → one head row dead-lettered (round 2: five).

Open (follow-up):
1. Medium: transport failures count toward the 5 attempts (`PublishOutboxBatch.php:69-77`), so a broadcaster outage >~15 s dead-letters the head row. Proposed contract change: connection/transport errors don't count as attempts; only rejections from a reachable broadcaster do. Accepted for now because pushes are hints (build plan §6).
2. Low-medium: no test that valid edge values pass (`available: 0`, `min_spend_cents: 0`, every drop reason, `capacity`).
3. Low: currency format rejection untested.
4. Low: BatchOutcome is a mutable side channel; a method on the action would be cleaner.
