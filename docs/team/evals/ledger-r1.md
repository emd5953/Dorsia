# Wallet ledger (ticket 03): eval round 1

**Verdict: FAIL, 89/100** (Correctness 36, Edge cases 20, Concurrency 19, Code quality 14). All gates pass: `make ci` 270 tests; Wallet module 122; arch 53 and catches three deliberate violations; concurrency 3/3; contract items present.

Raw-SQL attacks rejected: unbalanced, single entry, empty transaction, mixed currency, deferred unbalanced across statements, savepoint rollback removing a leg, orphan transaction id, SET CONSTRAINTS IMMEDIATE, UPDATE/DELETE/upsert on entries and transactions, negative member balance, single unbalanced entry on a committed transaction. Accepted: balanced pair appended to a committed transaction (D1); account identity columns updatable (D4); TRUNCATE (exempt by contract).

Mutation testing: 38 mutants, 34 killed; real survivor: removing `ON CONFLICT DO NOTHING` (fallback untested, D3). Mixed stress with concurrent reconcile: no deadlocks, no false drift.

Defects:
1. Major: balanced entries can be appended to a committed transaction (`2026_10_04_000400_create_ledger_triggers.php:14-45`). Fix: BEFORE INSERT trigger on `ledger_entries` rejecting rows unless the parent transaction row was written in the current DB transaction (`xmin = pg_current_xact_id()::xid`).
2. Major: zero-share cancels (0/0 split, 1-cent late, 0-cent early) throw and would 500 the cancel (`PostCancellationRefund.php:40-63`). Architect ruling (C§5.3): drop zero legs; both zero → post nothing, `transactionId = null`, no `wallet.updated`.
3. Minor: idempotency-key insert-conflict fallback (`PostTransaction.php:69-74`) untested.
4. Minor: `ledger_accounts` currency/owner/kind can change after entries exist; changing `owner_id` moves a member's money. Fix: BEFORE UPDATE trigger allowing only `balance_cents`, `updated_at`.
5. Nit: posting-action constructors take `(Outbox, PostTransaction)`; contract says `(Outbox)`.
