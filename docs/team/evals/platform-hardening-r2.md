# Platform hardening 2 (feat/platform-hardening-2 @ 949f43b): eval

**Verdict: MERGE.** `make ci` green (159 tests); scope limited to allowed paths; Concurrency public API unchanged; new test seams reset in afterEach; concurrency group 10/10 on 5 runs. Barrier, timeout, crash cleanup, form-body ksort, log redaction, `Timestamps::FORMAT`, `bind()` UTC, `$dateFormat` on 3 models and precision-6 migrations each fail a test when mutated away.

Open minors (deferred to a later Platform batch):
1. `Timestamps::bind` usages in `EnsureIdempotency` and `PruneIdempotencyKeys.php:20` can be removed without a test failing.
2. The "real error reported before timeout" path in `tests/Support/Concurrency.php` (~line 101) is never exercised.
3. Sanctum's `PersonalAccessToken` has no `$dateFormat`, so `last_used_at`/`expires_at` still write whole seconds (C§3 gap).
4. Nit: crash path SIGKILLs pids Fork may already have reaped.
5. Nit: `ConcurrencyRunTest.php:101` would flake under parallel test runs.
