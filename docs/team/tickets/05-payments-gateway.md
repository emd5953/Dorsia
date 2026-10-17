# 05 — Payment gateway and webhooks

| | |
| --- | --- |
| Owner | Payments & real-time |
| Depends on | 01 merged |
| Parallel with | 02, 03, 04 |
| Blocks | 06, 08 |
| Branch | `feat/payments-gateway` off `build/v1` |
| Worktree env | `bin/worktree-env 05` → `dorsia-05`, ports 8500 / 8580 / 5932 / 6879 |
| Build plan | §3 (`payments`: unique `provider_ref`), §4 step 5 (gateway authorize for the card remainder) and the "payment fails → release" edge (release itself is done by 06), §6 "Payments (stubbed)", §9 step 6 |
| Contracts | C§3 Payments tables, C§5.5, C§8 webhook endpoint, C§9 Payments codes, C§11, C§12 `payments.*`, D7, D8, D9 |

## Files you may touch

- `app/Modules/Payments/**`
- `tests/Modules/Payments/**`, `tests/Arch/Contracts/PaymentsContractTest.php`

## Non-goals

`StripeGateway`, Stripe Connect payouts (the fake implements `payout()` but nothing calls it), membership billing, card refunds on cancellation (cancellations refund to wallet credit), ledger postings (Wallet), hold release (Reservations does it on decline/timeout).

## Work

1. Migrations (prefix `2026_10_05_`): `payments`, `payment_webhook_events` per C§3.
2. Models + factories.
3. `Contracts\PaymentGateway`, `Data\AuthorizeRequest`, `Data\GatewayResult`, `Data\AuthorizePaymentInput`, `Data\PaymentData`, enums, exceptions per C§5.5.
4. `Support\FakeGateway` per C§11: Redis-backed provider state, gateway-side idempotency, token knobs, programmatic knobs, config knobs, static `webhookPayload()` / `sign()`, latency. Bound as a singleton in `PaymentsServiceProvider` when `payments.gateway = fake`; any other value throws at boot.
5. Actions `AuthorizePayment`, `MarkPaymentForCapture`, `VoidPayment` per C§5.5.
6. Jobs `CapturePayment`, `RefundPayment`, `DeliverFakeWebhook`.
7. `POST /api/webhooks/payments/fake` (no auth) → `Support\HandleWebhook` per C§11 transition table.
8. `config.php` with `payments.gateway` and `payments.fake.*`.

## Acceptance criteria

- [ ] `tok_ok` authorize → payment `authorized` with `provider_ref` like `fake_ch_…`; `CapturePayment` → `captured`.
- [ ] `tok_decline` / `tok_insufficient_funds` / unknown token → `PaymentDeclinedException` (402 `payment_failed`, `details.decline_code` = `card_declined` / `insufficient_funds` / `invalid_token`), payment `failed` with `decline_code`.
- [ ] `tok_timeout` → `PaymentTimeoutException` (504 `payment_timeout`), payment `timed_out`, and the fake holds an authorized charge provider-side; a subsequent `payment.authorized` webhook for it sets `provider_ref` and dispatches a void that ends in `refunded`.
- [ ] `tok_capture_fail` → capture job retries, ends `capture_failed` and logs critical.
- [ ] Programmatic knobs `declineNext`, `timeoutNext`, `failNextCapture`, `latency` each work and reset after use where "Next".
- [ ] `AuthorizePayment` idempotency: second call for the same hold returns the same payment, gateway `authorizeCalls()` = 1; after a decline, a repeat rethrows the same decline without calling the gateway; a repeat with a different amount → `PaymentConflictException`; a repeat while the first is `pending` → `PaymentInProgressException`.
- [ ] `AuthorizePayment` called inside a transaction throws `LogicException` (D17).
- [ ] `MarkPaymentForCapture` attaches once; a second attach (other reservation id) → `PaymentConflictException`; the capture job is dispatched only after commit (rolled-back transaction → no job).
- [ ] `VoidPayment` refunds an unattached authorized payment once; is a no-op for attached, failed, already refunded; after `VoidPayment` wins, `MarkPaymentForCapture` on that payment throws `PaymentConflictException`.
- [ ] Gateway-side idempotency: `authorize` twice with the same key on the fake returns the same `provider_ref`, `charges()` has one entry.
- [ ] Webhook: bad signature → 401 `invalid_signature`, nothing stored; valid event → 200 `{duplicate:false}` and transition applied; same `id` again → 200 `{duplicate:true}`, no change; every row of C§11's transition table covered, including no-op backward transitions (`payment.authorized` on a `captured` payment changes nothing); unknown payment → 200 and event stored.
- [ ] `emitWebhooks(true)` + `duplicateWebhooks(true)` (and `tok_dup_webhook`) deliver each event twice through the real HTTP endpoint; the payment ends in the same state as with single delivery.
- [ ] `payments.provider_ref` unique constraint rejects a duplicate via raw SQL.
- [ ] `payments:reap` (scheduled every minute, C§13): a `pending` payment older than 120 s becomes `timed_out` (so a retry gets 504 `payment_timeout` instead of `payment_in_progress` forever); an `authorized` payment with no reservation older than hold TTL + 120 s is voided; younger rows and attached payments are untouched; a second run changes nothing.
- [ ] Gates: `make ci`, `make arch`, `PaymentsContractTest`.

## Required tests

- Unit: signature generation/verification (constant-time compare used), webhook payload builder.
- Feature: one per acceptance bullet (`tests/Modules/Payments/Feature/*`). Use `Http::fake()` only to capture outgoing webhook deliveries if not routing through the kernel; prefer posting to the real route.
- Concurrency (≥ 20 processes):
  - 20 parallel `AuthorizePayment` for the same hold → exactly one gateway charge (`charges()` count 1 in Redis), exactly one `payments` row; others get the same payment or `PaymentInProgressException`.
  - 20 parallel deliveries of the same webhook event → one `payment_webhook_events` row, one transition, all responses 200.
  - 20 parallel `VoidPayment` + `MarkPaymentForCapture` races on one payment → final state is exactly one of {attached + capture job, refunded}, never both.
- `tests/Arch/Contracts/PaymentsContractTest.php`.

## Eval agent will check

- `PaymentGateway` has exactly `authorize`, `capture`, `refund`, `payout` with C§5.5 signatures; domain code depends only on the interface (`grep FakeGateway` outside the provider and tests returns nothing).
- §6 simulation requirements: declines, timeouts and duplicate webhooks each have a passing test.
- No network/gateway call happens inside a DB transaction (read every action and job).
- Forward-only webhook state machine; idempotent on `event_id` and safe on `provider_ref`.
- Concurrency suite run 3 times.
