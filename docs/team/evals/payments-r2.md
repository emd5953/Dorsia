# Payments (ticket 05): eval round 2

**Verdict: FAIL, 85/100** (Correctness 35, Edge cases 21, Concurrency 16, Code quality 13). Gates pass: `make ci` 471 tests; arch 65 and catches violations; concurrency 3/3; `payments:reap` scheduled every minute without overlap on one server; 120 s pending boundary correct ("older than").

D1–D5 from round 1: all fixed; removing any fix fails a test.

Round-1 survivors: killed A13, A16, F11, G7. Still surviving: R5 refund/void key, H7 `lockForUpdate`, H15 webhook transaction, S4 trimmed body (the new test signs `$raw.' '` and sends `$raw`, the wrong way round), F14 currency check in the fake, F36 emit when another caller created the charge (the new test takes the early return at `FakeGateway.php:146-149` and never reaches the guard). Reaper: removing the hold TTL or the 120 s grace from the orphan threshold survives (test only compares 421 s vs 0 s).

Reaper races: safe against webhooks, capture job and MarkPaymentForCapture; repeat runs change nothing; broken against a late authorize (defect 1).

New defects:
1. Medium: `AuthorizePayment.php:118-128` — gateway call outlives 120 s, reaper sets `timed_out`, gateway then returns Authorized: `provider_ref` discarded, caller gets 409 instead of 504, live authorization never refunded. Fix: on `timed_out`, set `provider_ref` where null, dispatch RefundPayment after commit, throw PaymentTimeoutException.
2. Medium: `VoidPayment.php:42-48` catches only GatewayTimeoutException; any other error leaves the row `refunded` with the charge still authorized, queues no RefundPayment, makes AuthorizePayment throw RuntimeException, and stops `ReapPayments.php:37` from voiding the rest of the batch. Fix: catch Throwable, log, dispatch RefundPayment.
3. Low: survivor tests S4 and F36 don't kill their mutants; R5, H7, H15, F14 and the orphan threshold have no killing test.
4. Nit: unreachable `$ref === null ? []` branch at `HandleWebhook.php:155`.

The eval's mutation script and probes are in `payments-r2-tools/`.
