# 03 — Wallet ledger

| | |
| --- | --- |
| Owner | Ledger |
| Depends on | 01 merged |
| Parallel with | 02, 04, 05 |
| Blocks | 06, 08 |
| Branch | `feat/wallet-ledger` off `build/v1` |
| Worktree env | `bin/worktree-env 03` → `dorsia-03`, ports 8300 / 8380 / 5732 / 6679 |
| Build plan | §3 (`ledger_accounts`, `ledger_transactions`, `ledger_entries`, conventions), §5 (rules, and the rows: book with card, book with coupons/credit, cancel 8+ hours, cancel inside 8 hours, staff goodwill credit as an action only), §9 step 4 |
| Contracts | C§3 Wallet tables + triggers, C§5.3, C§5.4, C§7 `wallet.updated`, C§8 `GET /api/wallet`, C§9 `wallet_balance_changed`, C§12 `wallet.*`, C§13 `wallet:reconcile`, D9, D10, D14, D22, D23 |

## Files you may touch

- `app/Modules/Wallet/**`
- `tests/Modules/Wallet/**`, `tests/Arch/Contracts/WalletContractTest.php`

## Non-goals

Coupon grants, coupon lots and expiry, membership end, overage settlement, venue payouts, compensation, admin endpoints, refunds to card. (Their §5 rows are out of scope; only the account kinds exist.) No `coupon_lots` table.

## Work

1. Migrations (prefix `2026_10_04_`): three tables, every CHECK and unique in C§3, and the three triggers. System accounts are created lazily by the account resolver (C§3), not by the migration.
2. Models + factories (factories must go through `PostTransaction`, never insert raw entries).
3. `Support\PostTransaction`: the only writer of ledger rows. Inputs: type, reference, idempotency key, currency, list of `(account, signed amount)`. Resolves/creates accounts (`INSERT ... ON CONFLICT DO NOTHING`), locks all touched accounts `FOR UPDATE` in ascending id order, inserts the transaction, inserts **all entries in one multi-row INSERT**, updates `balance_cents` per C§5.4, returns `LedgerTransactionData`. Replays an existing idempotency key by returning the stored transaction.
4. Public actions, DTOs, enums, exception per C§5.3.
5. `wallet.updated` outbox row (via `App\Shared\Outbox\Outbox`) for each posting that touches a member account, with post-transaction balances.
6. `GET /api/wallet` (`auth:sanctum`).
7. `wallet:reconcile {--rebuild}` command + daily schedule.
8. `WalletServiceProvider::boot()` asserts `member_bps + platform_bps <= 10000`.
9. `Database/Seeders/WalletSeeder`: demo member gets a 50000-cent goodwill credit via `IssueGoodwillCredit` with key `seed:demo:goodwill` (re-runnable).

## Acceptance criteria

- [ ] Direct SQL insert of an unbalanced transaction (one INSERT of entries summing to 100) fails at commit with the trigger message; a balanced two-entry insert commits.
- [ ] A transaction row with fewer than 2 entries fails at commit.
- [ ] Entries whose account currency differs from the transaction currency fail.
- [ ] `UPDATE` or `DELETE` on `ledger_entries` / `ledger_transactions` fails with `ledger is append-only`.
- [ ] Member account balance can never go negative (DB CHECK rejects it even if code tried).
- [ ] Booking $1,000 by card only: entries debit card clearing +100000, credit venue_payable −100000; balances: card clearing 100000, venue_payable 100000.
- [ ] Booking with credit only, and with credit 30000 + card 70000: the right lines, zero-amount lines omitted, member credit decreases by exactly the credit part.
- [ ] Cancel early on 100000: venue_payable −100000 (balance decreases by 100000), member credit +100000.
- [ ] Cancel late on 100000 with defaults: venue_payable debited 75000, member credit +50000, platform +25000, `venueRetained = 25000`. On 99999: member 49999, platform 24999, venue retains 25001 (D14).
- [ ] Changing `wallet.late_cancel.*` config changes the split without code changes (test with 6000/2000).
- [ ] Zero legs: with `platform_bps = 0` a late cancel posts two lines; with both bps = 0 it posts nothing and returns `transactionId = null`.
- [ ] `PlanSpend`: coupon then credit then card; amount smaller than credit → card 0; zero balances → all card. (Coupon balance is seeded in a test by posting a balanced test transaction through `PostTransaction`, since nothing grants coupons yet.)
- [ ] `PostBookingPayment` with a plan whose credit exceeds the current balance → `InsufficientWalletBalanceException` (409 `wallet_balance_changed`), nothing written.
- [ ] Every posting action replays on its idempotency key: second call returns the same transaction id, no new entries, balances unchanged, no second `wallet.updated` row.
- [ ] `PostBookingPayment` / `PostCancellationRefund` throw `LogicException` outside a transaction.
- [ ] `wallet.updated` payload matches C§7 with post-transaction balances; written only when a member account is touched.
- [ ] `GET /api/wallet` returns `{currency, credit_cents, coupon_cents}` (zeros for a new member).
- [ ] `wallet:reconcile` exits 0 on a clean ledger; after manually corrupting one `balance_cents` it exits 1, names the account and logs critical; `--rebuild` fixes it and a re-run exits 0.
- [ ] Misconfigured split (`member_bps + platform_bps > 10000`) fails application boot.
- [ ] Gates: `make ci`, `make arch`, `WalletContractTest`.

## Required tests

- Unit: `SpendPlan` total and currency mismatch; late-cancel split math including odd cents.
- Feature: one per acceptance bullet; trigger tests use `SET CONSTRAINTS ALL IMMEDIATE` (already set in Feature bootstrap) and single-statement inserts.
- Concurrency (`tests/Modules/Wallet/Concurrency/*`, ≥ 20 processes):
  - Member with 100000 credit; 20 parallel `PostBookingPayment` calls (distinct holds) each planning 30000 credit → exactly 3 succeed, 17 raise `InsufficientWalletBalanceException`; final credit balance 10000; reconcile clean.
  - 20 parallel `PostCancellationRefund` calls for the **same** reservation → exactly one transaction; member credit increased once.
  - 20 parallel bookings for 20 different members into the same venue's `venue_payable` → no deadlock, venue balance = sum, reconcile clean.
- `tests/Arch/Contracts/WalletContractTest.php`.

## Eval agent will check

- Each §5 in-scope row reproduced in a test with the build plan's example numbers ($1,000 card, $1,000 credit, early cancel, late cancel 750/500/250).
- Trigger SQL read line by line: deferred, row-level, checks sum, count and currency.
- That `PostTransaction` is the only code inserting into ledger tables (`grep`), uses one INSERT for entries, and locks accounts in id order.
- That balance orientation matches C§5.4 for every kind.
- Reconcile detects drift, a non-zero global sum and an unbalanced transaction (the latter by disabling the trigger inside a test transaction).
- Concurrency suite run 3 times without flakes.
