# Wallet ledger (ticket 03): eval round 3

**Verdict: FAIL, 91/100** (Correctness 37, Edge cases 23, Concurrency 19, Code quality 12). Score clears the bar but two gates fail:
- `make ci` red at Pint on `docs/team/evals/payments-r2-tools/ProbeTest.php`, an orchestrator commit on build/v1 (fixed by renaming to `.txt`). Ledger code itself: Pint, stan and 431 tests pass.
- Contract gate: C§3 item 5's `BEFORE DELETE` on `ledger_accounts` is missing; unused accounts can be deleted.

All round-2 defects fixed and caught by tests. At xid epoch 1: nested-savepoint postings commit; appends to committed transactions, `created_xid` updates and explicit past/future `created_xid` values are all rejected or unpostable. All 14 round-1 raw-SQL attacks rejected. 45-process stress, 5 rounds: 200/200 reconciles clean, no deadlocks.

Defects:
1. Moderate (contract gate): add DELETE to the account guard (`2026_10_04_000500_create_ledger_guard_triggers.php:57-61`) plus a schema test.
2. Minor: the `now()` arch regex at `WalletContractTest.php:209` misses heredoc `VALUES (now())`, `DB::raw('now()')`, `DB::raw('NOW()')` and `clock_timestamp()`.
3. Minor: CI can't detect a revert to the xmin guard; add a schema test on the trigger source.
