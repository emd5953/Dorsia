# Real-time via the outbox (ticket 07): eval round 2

**Verdict: FAIL, 89/100** (Correctness 35, Edge cases 22, Concurrency 19, Code quality 13). Gates pass: `make ci` 635 tests; arch 77; concurrency 3/3; no broadcasting outside Realtime; `outbox` service has `restart: unless-stopped`. All round-1 defects fixed; round-1 survivors killed except equivalent M27/M30b. k6 smoke exits 99 on missing/wrong event, 0 on the right one.

Live probes: poison rows dead-lettered on first attempt, later rows in order, no re-reporting; Postgres stopped 10 s → publisher survived and resumed; 3 publishers, 600 rows → exactly-once, ordering only per publisher (contract assumes one); Reverb down ~60 s → one head row dead-lettered per failure window (5 lost).

Defects:
1. Medium: C§5.7 per-topic shape/type check missing (`PublishOutboxBatch.php:38-40,73-94`, `ChannelMap.php:29-38`); incomplete or wrongly typed payloads go out. Tests use incomplete payloads (`PublishOutboxBatchTest.php:77-78,118-119`).
2. Medium-low: no test that dead rows are excluded from later batches (D3b/D3g survive).
3. Low-medium: broadcaster outage burns through live rows (100 ms retry); use the 1–30 s backoff for broadcast failures too.
4. Low: DB-outage loop recovery has no automated test (4 loop mutants survive).
5. Low: `Log::critical` inside the transaction can repeat on commit failure; log after commit.
6. Low: concurrency test sums counts instead of asserting dispatches per `outbox_id`.

Orchestrator decision on extra payload keys: keep dropping them (allowlist) and log a warning; missing or mistyped required keys are dead-lettered (defect 1).
