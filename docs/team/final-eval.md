# Final product evaluation — `build/v1`

Date: 2026-10-18  
Evaluated branch: `eval/final`  
Evaluated commit: `5463c57` (ticket 08 merged)  
Comparison: full `main...HEAD` diff (452 files, 42,425 insertions, 1 deletion)

## Scope and result

This evaluation covers build-plan steps 1–8. Memberships and coupon grants, admin API/staff guard, Expo, Stripe, production deployment, rate limiting, and push notifications/tier targeting are out of scope under `docs/team/process.md` and ticket 07. The merged worktree was clean at the start. No application source was changed during this evaluation; the only repository change from this evaluation is this report.

Fresh setup, CI, architecture checks, end-to-end booking, reconciliation, and the 500-VU diagnostic passed. The supplied three 5,000-VU host runs each sold exactly 50 reservations with clean ledgers and no 5xx, but confirm p95 was 325–566 ms against the 300 ms target. The Linux reference run required by the process has not been recorded. The detailed evidence and remaining evaluation items follow.

## 1. Fresh setup from zero

- `bin/worktree-env 09` selected `dorsia-09` and ports 8900/8980/6332/7279.
- Initial `make setup` hit the sandbox permission boundary at Docker Buildx’s default activity directory. Re-running with `BUILDX_CONFIG=/private/tmp/dorsia-buildx09 make setup` completed successfully.
- Setup built the images, installed Composer dependencies, started Postgres/Redis/app/worker/scheduler/outbox/Reverb, ran `migrate:fresh --seed`, and completed the demo member, inventory, and wallet seeders.
- Fresh database migrations and seeders also ran successfully for the drop test using `make drop-seed`.

## 2. CI and architecture gates

| Gate | Command | Result |
| --- | --- | --- |
| Pint | `make ci` → `make pint` | Pass, 368 files |
| PHPStan level 8 | `make ci` → `make stan` | Pass, no errors |
| Full Pest suite on Postgres | `make ci` → `make test` | Pass, 993 tests / 4,814 assertions |
| Architecture and contract suite | `make arch` | Pass, 106 tests / 900 assertions |

## 3. Drop test and confirm-path profile

### 5,000-VU acceptance results

The existing host-run results in `loadtest/RESULTS.md` record three 5,000-VU runs on the macOS host, with the app and services in Docker Desktop. Each run had exactly 50 confirmations, 4,950 sold-out responses, zero 5xx, zero unexpected/replay-mismatch responses, zero double bookings, zero duplicate charges, and a clean ledger. `make drop-verify` passed after each. Hold p95 values were 63–297 ms; confirm p95 values were 325, 566, and 373 ms. Thus correctness passed 3/3; confirm latency missed the <300 ms target 3/3. The k6 container was OOM-killed near 1,600–1,972 VUs, so these 5,000-VU runs used host k6 as recorded in the results file.

### Moderate-load profile

Command: `make drop-seed`, then `docker compose --profile loadtest run --rm -e DROP_VUS=500 -v /private/tmp/dorsia-results:/loadtest/results k6 run /loadtest/drop.js`. Before the traffic window, Postgres `pg_stat_statements` and Redis command counters were reset. The diagnostic result was 50 confirmed, 450 sold out, zero 5xx, zero unexpected responses; hold p95 7.06 ms, confirm p95 28.34 ms. The JSON summary was written under `/private/tmp/dorsia-results`.

Component evidence from that request window:

- Fake gateway `latency_ms` was 0. Its test-double call therefore adds no configured sleep.
- Postgres statement execution was small: 50 reservation inserts totaled 2.77 ms; 50 payment inserts 3.08 ms; 50 quote inserts 1.26 ms; 50 hold inserts 3.05 ms. Across 1,100 idempotency-key inserts for the hold and confirm requests/retries, total execution was 34.26 ms (0.031 ms per statement). Ledger-entry writes in the traffic window totaled 4.50 ms. These are aggregated statement times, not end-to-end request traces.
- Redis recorded 1,225 EVAL calls totaling 11.22 ms; the 502 DECR calls totaled 0.264 ms and the 502 INCR calls 0.196 ms. Redis service time was a small part of the request duration.
- The remaining request time is in PHP/framework processing, database/network round trips not represented by server execution time, and Docker Desktop scheduling. No PHP profiler was enabled, so this run cannot split that remainder further.

The measured confirm path at 500 VUs is comfortably below 300 ms, while Postgres execution, configured gateway delay, and Redis command time are all low. This points to local saturation/scheduling at the 5,000-VU laptop run rather than an intrinsically slow gateway or database statement. It does not prove that conclusion: per process.md, Linux remains the reference for the 5,000-VU latency requirement.

## 4. Ledger reconciliation after drop

After the final 500-VU drop, `make drop-verify` passed its SQL checks and `wallet:reconcile` exited 0. It checked 1,003 accounts; the global entry sum was 0, unbalanced transactions were 0, and accounts with drift were 0. The verifier also checked the reservation count, capacity, duplicate payments, orphan payments, gateway charge count, and booking transaction count.

## 5. End-to-end booking story

Command: `make test ARGS=tests/Modules/Reservations/Feature/EndToEndTest.php`.

Pass: one real HTTP/kernel feature test, 33 assertions. It searches availability, creates a quote-bearing hold, confirms with 20,000 cents of wallet credit plus 55,000 cents on card, cancels early and sees a 75,000-cent wallet refund, books again wallet-only, cancels late, and checks final balances: member credit 37,500; venue payable 18,750; platform −1,250; card clearing 55,000. It also checks slot counts and calls `wallet:reconcile` successfully.

## 6. In-scope build-plan rule-to-test map

All rows below passed in `make ci`; dedicated commands are listed where the rule also received a separate run.

| In-scope rule | Passing test evidence |
| --- | --- |
| API modules stay within the dependency table, expose agreed contracts, use strict types, DTO/action conventions, and avoid cross-module tables/raw SQL | `tests/Arch/ModuleBoundariesTest.php`; all `tests/Arch/Contracts/*ContractTest.php`; `make arch` |
| PostgreSQL is authoritative for slot capacity; Redis gate sheds traffic and recovers from drift; no overbooking under races | `tests/Modules/Inventory/Feature/HoldSlotTest.php`, `GateAccountingTest.php`, `GateRefreshTest.php`; `tests/Modules/Reservations/Concurrency/HoldRaceTest.php`, `ReservationRaceTest.php` |
| Hold creation, quote transactionality, idempotency, expiry, release, ownership, and capacity boundaries | `tests/Modules/Inventory/Feature/HoldLifecycleTest.php`, `HoldSlotTest.php`; `tests/Modules/Reservations/Feature/HoldQuoteTest.php`, `HoldEndpointTest.php`; `tests/Shared/Feature/IdempotencyHardeningTest.php` |
| Venue-local timezones, availability, drop open/close, and sell-out statistics | `tests/Modules/Inventory/Feature/AvailabilityTest.php`, `CatalogEndpointsTest.php`, `SelloutStatsTest.php`, `SweeperTest.php`; `tests/Modules/Inventory/Feature/InventorySeederTest.php` |
| Search deduplicates per member/venue/local date/hour/party size; party bands and unmet demand feed scheduled signals; thin bands fall back; stale signals do not block quotes; quotes lock to holds | `tests/Modules/Pricing/Feature/RecordSearchTest.php`, `RecordSearchDstTest.php`, `ComputeDemandSignalsTest.php`, `SignalsLockAndScheduleTest.php`, `QuoteHoldTest.php`, `QuoteHoldReplayTest.php`; `tests/Modules/Pricing/Concurrency/PricingConcurrencyTest.php` |
| Integer-cents double-entry transactions balance; database triggers reject imbalance and ledger mutation; spend order is coupon, credit, card; reconciliation finds drift | `tests/Modules/Wallet/Feature/LedgerSchemaTest.php`, `BookingPaymentTest.php`, `PlanSpendTest.php`, `ReconcileLedgerTest.php`; `tests/Modules/Wallet/Concurrency/LedgerConcurrencyTest.php` |
| Cancellation boundary and configured split; refunds credit the wallet even for card bookings | `tests/Modules/Reservations/Feature/CancelReservationTest.php`; `tests/Modules/Wallet/Feature/CancellationRefundTest.php`; `tests/Modules/Wallet/Unit/LateCancelSplitTest.php` |
| Confirm authorization occurs outside the transaction; declines/timeouts release holds; post-authorization failures void; capture is queued; retries do not duplicate reservations/payments | `tests/Modules/Reservations/Feature/ConfirmReservationTest.php`; `tests/Modules/Reservations/Concurrency/ReservationRaceTest.php` |
| Fake gateway contract, idempotent authorization/capture/refund, webhook signature and duplicate handling | `tests/Modules/Payments/Feature/AuthorizePaymentTest.php`, `FakeGatewayTest.php`, `CaptureAndVoidTest.php`, `WebhookTest.php`; `tests/Modules/Payments/Concurrency/PaymentsRaceTest.php` |
| Outbox writes are transactional; publisher orders and retries rows; dead-letters poison rows; `SKIP LOCKED` prevents duplicate concurrent publication; private channels authorize only their member | `tests/Shared/Feature/OutboxTest.php`; `tests/Modules/Realtime/Feature/PublishOutboxBatchTest.php`, `ChannelMapTest.php`, `ChannelAuthTest.php`; `tests/Modules/Realtime/Concurrency/PublishOutboxBatchConcurrencyTest.php`, `PublishOutboxCommitFailureTest.php` |
| Shared auth, idempotency fencing/replay, error envelope, money arithmetic, timestamps and microsecond persistence | `tests/Shared/Feature/AuthTest.php`, `IdempotencyHardeningTest.php`, `ErrorEnvelopeTest.php`, `TimestampsTest.php`; `tests/Shared/Unit/MoneyTest.php`; `tests/Modules/Wallet/Feature/MicrosecondTimestampsTest.php` |
| Drop shape, same-key response retries, 50 winners, no 5xx, clean ledger and reconciler | `tests/Performance/DropTestSeederTest.php`; `loadtest/drop.js`; `loadtest/verify.sql`; 500-VU diagnostic above; existing three 5,000-VU results in `loadtest/RESULTS.md` |
| Docker stack, fresh migration/seed, static analysis, formatting, full suite | `make setup`; `make ci`; `make arch` |

Push notifications and tier-ordered drop-in targeting are explicitly out of scope in ticket 07 and contract decision D20; the in-scope `slot.released` event remains written and broadcast.

## 7. Adversarial review of full diff vs `main`

Reviewed the merged diff across Shared/platform code and all six business modules, with attention to authorization, SQL construction, races, and money.

- **Security:** member-owned reservation and hold reads are scoped to the authenticated member; write endpoints use Sanctum and idempotency middleware; private-member broadcast authorization checks the member ID; webhook signatures use constant-time comparison and reject missing secrets. Cross-module raw SQL and internal-model access are covered by architecture tests. I found no injection, cross-member access, or credential disclosure blocker in the reviewed diff.
- **Races:** the Postgres capacity constraint and conditional updates remain authoritative; hold and reservation transitions lock rows; idempotency completion is fenced; wallet posting locks accounts in order and uses unique transaction keys; outbox workers claim rows with `SKIP LOCKED`. Concurrency suites passed, including three consecutive Reservations runs.
- **Money:** amounts use integer cents and currency-bearing value objects; balanced ledger triggers and append-only protections are present; cancellation writes new ledger entries, and the reconciliation check found no drift. No money-corruption blocker was found.
- **Known risks:** the follow-up list below includes deferred gateway refund recovery, local-vs-Linux latency evidence, and lower-severity unpinned edge cases. None was observed to corrupt balances in the executed scenarios.

## 8. Reservations mutation probes

Pest’s built-in mutation command could not start because the Docker PHP image has no coverage driver. To keep the evaluated worktree unchanged, targeted mutations were applied one at a time to `/private/tmp/dorsia-mut`, then reverted before the next run. The exact source/needle and full output are in `/private/tmp/dorsia_mutation_trials.py` and `/private/tmp/dorsia-mut/mutation-results.json`.

| # | Mutant | Target test | Outcome |
| --- | --- | --- | --- |
| 1 | Disable confirm action replay | Confirm same-key domain backstop | Killed |
| 2 | Disable expired-hold classification | Confirm exact expiry test | Killed |
| 3 | Disable active-hold guard in confirm | Confirm converted hold under a new key | **Survived**; `ConvertHoldToBooking` independently rejects the converted hold, so the feature behavior remains guarded downstream |
| 4 | Omit release after gateway decline | Decline cleanup test | Killed |
| 5 | Omit release after gateway timeout | Timeout cleanup test | Killed |
| 6 | Omit booking ledger posting | Card-only confirmation ledger assertions | Killed |
| 7 | Omit capture scheduling | Card-only confirmation queue assertion | Killed |
| 8 | Omit `reservation.confirmed` outbox write | Card-only confirmation event assertion | Killed |
| 9 | Omit void on expiry detected after authorization | Expiry/void test | Killed |
| 10 | Omit `confirm_failed` release after card authorization | Wallet-drained-after-authorization test | Killed |
| 11 | Change exact cancellation boundary from early to late | Exact 8-hour cancellation test | Killed |
| 12 | Disable started-reservation rejection | Cancellation at `starts_at` | Killed |
| 13 | Disable confirmed-status guard | Cancel twice with a new key | Killed |
| 14 | Omit slot release on cancellation | Early cancel slot/gate assertions | Killed |
| 15 | Replace cancellation refund with zero refund | Early cancel refund assertions | Killed |
| 16 | Force every cancellation to classify as early | Late cancellation test | Killed |
| 17 | Remove cancellation row lock | 20 parallel cancels (`ReservationRaceTest.php`, filter `parallel cancels`) | Killed: mutant produced 20 HTTP 200 responses; test requires exactly one |

Result: 16 killed, one behaviorally redundant survivor. After restoration, the confirm/cancel feature tests passed (46 tests / 417 assertions). The full Reservations concurrency directory passed three runs (9 tests / 335 assertions each). Both mutated files compare byte-for-byte equal to the evaluated source.

## 9. Open follow-ups collected from the latest eval reports

Later rounds supersede earlier round defect lists; the items below are the open/deferred items in the latest applicable reports. The Reservations r3 request for mutation proofs is completed by section 8. These follow-ups are not all blockers for this final verdict.

### Inventory — `inventory-r5.md`

1. Same-key callers that lose the hold-key lock can receive SoldOut/ActiveHoldExists instead of a replay; replay before either timeout-path error. Gate over-count is safe but inconvenient.
2. Pin hold-key lock name, TTL, and wait timeout; add different-key parallelism coverage so a global lock cannot silently serialize a drop.
3. `InventoryContractTest` does not pin `inventory.hold_key_lock.seconds` and `wait_seconds`.
4. Exact own-hold expiry boundary (`expires_at`) lacks a dedicated test.

### Wallet/platform timestamps — `ledger-r4.md`

1. Wallet’s no-SQL-time scan misses interpolated double-quoted strings, `now( )`, and `'now'::timestamptz`.
2. The delete guard was added by editing an unmerged migration in place; migration history should be kept append-only after release.

### Pricing — `pricing-hardening-r1.md`

1. No non-transactional listener-path test for `RecordAvailabilitySearch` savepoint behavior (the later tree has a no-transaction listener test; the eval report still lists this for owner confirmation).
2. No test pins `ComputeDemandSignals` advisory locking as transaction-scoped.
3. Inventory reads run within the signals transaction and hold locks until commit, which can block DDL.
4. Overflow guard is conservative and there is no negative-base check.
5. `QuoteHold` takes sell-out p50 from rows without a defined order.

### Payments — `payments-r3.md`

1. If the reaper, webhook and refund finish before a gateway returns after 120 seconds, authorization can return PaymentConflict and leave the hold with a spent key; treat Refunded like TimedOut and only dispatch refund for TimedOut.
2. No test throws an `Error` from `VoidPayment`’s refund path.
3. No parallel test covers `metadata.payment_id` webhook lookup when `provider_ref` is null.
4. `VoidPayment` marks a row refunded before gateway confirmation; permanent refund failure has only a critical log and no retry/revisit mechanism.

### Platform hardening — `platform-hardening-r2.md`

1. `Timestamps::bind` usage in idempotency and pruning can be removed without a test failing.
2. The concurrency helper’s “real child error reported before timeout” path lacks a test.
3. Sanctum `PersonalAccessToken` lacks `$dateFormat`, so `last_used_at`/`expires_at` can lose microseconds despite C§3.
4. Crash cleanup may SIGKILL a child already reaped by Fork (nit).
5. `ConcurrencyRunTest.php:101` may flake under parallel test execution (nit).

### Realtime — `realtime-r3.md`

1. Transport failures count toward the five attempts, so a broadcaster outage longer than about 15 seconds can dead-letter the head row. Proposed contract change: only reachable-broadcaster rejections count; accepted for now because pushes are hints.
2. No valid-edge test covers `available: 0`, zero minimum spend, every drop reason, and capacity values.
3. Currency-format rejection lacks a test.
4. `BatchOutcome` is a mutable side channel; returning it from the action would be cleaner.

## Verdict: NOT READY

**Blocking item:** record the 5,000-VU latency reference run on Linux (or otherwise resolve the confirm p95 miss). The three supplied host runs pass correctness but miss the <300 ms confirm p95 target at 325–566 ms. The clean 500-VU diagnostic and component profile point toward local load-generator/Docker Desktop contention, but process.md makes Linux the reference when macOS latency is suspect. Until that run meets the target or identifies and fixes a code bottleneck, the product does not meet the stated drop-latency acceptance criterion.
