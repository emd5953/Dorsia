# Payments (ticket 05): eval round 3

**Verdict: PASS, 93/100** (Correctness 38, Edge cases 23, Concurrency 18, Code quality 14). `make ci` green (481 tests); arch catches FakeGateway and Wallet imports in domain code; concurrency 3/3; no gateway call inside a transaction.

Harness: 27/27 mutants killed (D4b regex retargeted to an equivalent refactor). 24 new mutants: 19 killed, 3 equivalent, 2 real gaps (N7, N19). Probes: reaper-then-late-authorize → 504, charge refunded once; void refund throwing RuntimeException or TypeError → RefundPayment queued and succeeds.

Open (follow-up):
1. Low: `AuthorizePayment.php:126-139` — if the reaper, webhook and refund all finish before a >120 s gateway response, the row is `refunded` and the caller gets 409 PaymentConflict, which keeps the hold (C§6.2 step 4) with a spent key. Treat `Refunded` like `TimedOut` → PaymentTimeoutException; dispatch refund only for `TimedOut`.
2. Low: N7 — no test throws an `\Error` from VoidPayment's refund.
3. Low: N19 — no parallel test for the `metadata.payment_id` webhook lookup with null `provider_ref`.

Contract weakness (not charged): VoidPayment marks `refunded` before the gateway confirms; on permanent refund failure the only signal is a critical log and nothing revisits the row.
