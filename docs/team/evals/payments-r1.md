# Payments (ticket 05): eval round 1

**Verdict: FAIL, 86/100** (Correctness 36, Edge cases 20, Concurrency 17, Code quality 13). All gates pass: `make ci` 312 tests; arch 50 and catches FakeGateway use in domain code and a Wallet import; concurrency 3/3; interface has exactly the 4 C§5.5 methods. Signature check uses `hash_equals`; money is bigint cents; no tokens logged; no gateway call inside a DB transaction.

Mutation testing: 114 mutants, 98 killed; 12 real survivors: A13 post-authorize status re-check, A16 providerRef null guard, R5 refund/void key coupling, H7 lockForUpdate, H15 webhook DB::transaction, S4 trimmed-body signature, F11 emit_webhooks config, F14 currency check on capture/refund, F36 emit when another caller created the charge, G7 amount_cents column type.

Defects:
1. Medium: `AuthorizePayment.php:85-99` catches only GatewayTimeoutException; any other throwable or process death leaves the row `pending` forever, so every retry is 409 `payment_in_progress` and the table is blocked for the full hold TTL. Fix: catch Throwable → `timed_out` + PaymentTimeoutException; plus the `payments:reap` job now in the contract.
2. Medium: `HandleWebhook.php:87,132` — `payment.authorized` with null `provider_ref` moves pending→authorized with no ref; capture later fails. Require provider_ref for authorized/captured/refunded.
3. Low: `AuthorizePayment.php:101` records Authorized-with-null-ref as a decline, which can orphan a real authorization; treat as outcome-unknown (`timed_out`).
4. Low: `FakeGateway.php:365-368` — the global duplicate flag turns emission on by itself; it should only double deliveries already being emitted.
5. Low (needs architect): webhook amount/currency never compared to the payment; `payment.captured` arriving before `payment.authorized` is dropped.
6. Low: the 12 survivors above.
7. Contract escalation: the payment key is per hold, and C§6.2 step 6 voids the payment but keeps the hold on `wallet_balance_changed`, so every retry on that hold gets `payment_conflict` permanently.
