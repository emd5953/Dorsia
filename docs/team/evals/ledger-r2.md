# Wallet ledger (ticket 03): eval round 2

**Verdict: FAIL, 88/100** (Correctness 36, Edge cases 21, Concurrency 18, Code quality 13). Gates pass: `make ci` 425 tests; Wallet 131; arch catches violations. Round-1 D1–D4 all fixed and each fails a test when removed. Raw-SQL attack table all rejected (incl. append via savepoint, COPY, mixed new+old rows, account deletion). Legitimate two-statement posting across three nested savepoints commits. 62-process stress, 5 rounds with concurrent reconcile and rebuild: no deadlocks, no drift.

Defects:
1. Major (latent): the append guard (`2026_10_04_000500_create_ledger_guard_triggers.php:22`) converts `xmin::text::xid8` as epoch 0. After the cluster's xid counter wraps once (~4.29B transactions), every posting made inside a savepoint (Laravel nested transaction) is rejected. Reproduced with `pg_resetwal -e 1` on postgres:16.15. Fails closed (no corruption) but takes postings down. Related hypothesis: a frozen row's xmin could collide with a new top-level xid.
2. Minor/moderate: `PostCancellationRefund.php:50-57` returns the zero-share short-circuit before looking up the stored transaction for `reservation:{id}:cancel`, so a replay can return `null` after credit was actually granted.
3. Minor: no test for `member_bps=0` with `platform_bps>0`; identity guard tests don't cover `id`/`created_at`; `now()` arch regex at `WalletContractTest.php:209` is case-sensitive.
